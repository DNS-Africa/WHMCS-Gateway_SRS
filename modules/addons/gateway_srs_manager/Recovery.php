<?php
namespace GatewaySrsManager;

// Kept outside the replaceable registrar directory so a process interruption is recoverable.
final class Recovery
{
    public static function stateDirectory(string $root): string
    {
        $dir = defined('GATEWAY_SRS_STATE_DIR') ? GATEWAY_SRS_STATE_DIR
            : sys_get_temp_dir() . '/gateway-srs-' . substr(hash('sha256', $root), 0, 20);
        if (!is_dir($dir)) { return $dir; }
        if (is_link($dir) || (fileperms($dir) & 0777) !== 0700
            || (function_exists('posix_geteuid') && fileowner($dir) !== posix_geteuid())) {
            throw new \RuntimeException('The private module state directory is unsafe.');
        }
        return $dir;
    }

    public static function journal(string $root): array
    {
        $path = self::stateDirectory($root) . '/update.json';
        if (is_link($path)) { throw new \RuntimeException('The update journal is unsafe.'); }
        return is_file($path) ? (json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) ?: []) : [];
    }

    public static function recover(string $root): void
    {
        $dir = self::stateDirectory($root);
        if (is_link($dir . '/operations.lock')) { throw new \RuntimeException('The update lock is unsafe.'); }
        $lock = fopen($dir . '/operations.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('The module is busy. Retry after operations finish.'); }
        try {
            $journal = self::journal($root);
            $target = $root . '/modules/registrars/gateway_srs';
            $backup = $journal['backup'] ?? '';
            if (!in_array($journal['phase'] ?? '', ['installing', 'recovering'], true)
                || ($journal['target'] ?? '') !== $target || dirname($backup) !== dirname($target)
                || !preg_match('/^\.gateway_srs-backup-[a-f0-9]{16}$/D', basename($backup))
                || is_link($backup) || is_link($target)) {
                throw new \RuntimeException('No safe interrupted update was found. Restore a manual backup if necessary.');
            }
            if (is_dir($backup)) {
                self::remove($target);
                if (!rename($backup, $target)) { throw new \RuntimeException('Could not restore the previous module directory.'); }
            } elseif (!is_dir($target)) {
                throw new \RuntimeException('Both the module and its backup are missing. Restore a manual backup.');
            }
            if (function_exists('opcache_invalidate')) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php') { opcache_invalidate($file->getPathname(), true); }
                }
            }
            $stage = $journal['stage'] ?? '';
            if (dirname($stage) === dirname($target) && preg_match('/^\.gateway_srs-stage-[a-f0-9]{16}$/D', basename($stage))) {
                self::remove($stage);
            }
            $journal['phase'] = 'rolled_back';
            $temp = tempnam($dir, 'recovery-');
            if (!$temp || file_put_contents($temp, json_encode($journal, JSON_THROW_ON_ERROR)) === false
                || !chmod($temp, 0600) || !rename($temp, $dir . '/update.json')) {
                throw new \RuntimeException('The backup is restored, but the update journal could not be saved.');
            }
            if (function_exists('logModuleCall')) { logModuleCall('gateway_srs', 'Interrupted update recovered', [], []); }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) { throw new \RuntimeException('Cannot remove a failed update file.'); }
        } elseif (is_dir($path)) {
            foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $file) { self::remove($file->getPathname()); }
            if (!rmdir($path)) { throw new \RuntimeException('Cannot remove the failed update directory.'); }
        }
    }
}
