<?php
namespace WHMCS\Module\Registrar\gateway_srs;

final class Updater
{
    private string $directory;
    private RuntimeState $state;
    private ReleaseClient $releases;
    private string $whmcsVersion;

    public function __construct(string $directory, RuntimeState $state, ReleaseClient $releases, string $whmcsVersion)
    {
        $this->directory = rtrim($directory, '/');
        $this->state = $state;
        $this->releases = $releases;
        $this->whmcsVersion = $whmcsVersion;
    }

    public function availability(): ?string
    {
        if (!extension_loaded('zip') || !extension_loaded('openssl')) { return 'Enable PHP ZIP and OpenSSL to use admin updates.'; }
        if (!is_file($this->directory . '/release-public-key.pem')) { return 'Install the publisher public signing key before using admin updates.'; }
        if (is_link($this->directory) || !is_dir($this->directory) || !is_writable(dirname($this->directory))) {
            return 'The registrar directory must be a regular directory with a writable parent. Use a manual update on this host.';
        }
        if ($this->whmcsVersion === '') { return 'Cannot determine the WHMCS version. Use a manual update.'; }
        return null;
    }

    public function install(): string
    {
        $problem = $this->availability();
        if ($problem !== null) { throw new \RuntimeException($problem); }
        $lock = $this->state->lock('installer');
        $operationLock = null;
        $work = $this->state->path('download-' . bin2hex(random_bytes(8)));
        $stage = dirname($this->directory) . '/.gateway_srs-stage-' . bin2hex(random_bytes(8));
        try {
            $prior = $this->state->read('update');
            if (in_array($prior['phase'] ?? '', ['installing', 'recovering'], true)) {
                throw new \RuntimeException('Recover the interrupted update before installing another release.');
            }
            $release = $this->releases->latest(); // Never trust an admin POST's version, URL or cached release data.
            $installed = json_decode(file_get_contents($this->directory . '/version.json'), true, 512, JSON_THROW_ON_ERROR);
            if (!version_compare($release['version'], $installed['version'], '>')) {
                throw new \RuntimeException('The latest stable release is already installed or is older than this installation.');
            }
            foreach (['gateway_srs.zip', 'release-manifest.json', 'release-manifest.sig'] as $name) {
                if (!isset($release['assets'][$name])) { throw new \RuntimeException('This release has no signed update package. Use the manual download.'); }
            }
            $manifestBytes = $this->releases->fetch($release['assets']['release-manifest.json']);
            $signature = $this->releases->fetch($release['assets']['release-manifest.sig'], 16384);
            $manifest = self::verifyManifest($manifestBytes, $signature, file_get_contents($this->directory . '/release-public-key.pem'));
            if ($manifest['version'] !== $release['version']) { throw new \RuntimeException('The signed manifest does not match the published release.'); }
            $this->checkCompatibility($manifest);
            mkdir($work, 0700);
            $archive = $this->releases->fetch($release['assets']['gateway_srs.zip'], 20 * 1024 * 1024);
            if (!hash_equals($manifest['sha256'], hash('sha256', $archive))) { throw new \RuntimeException('The update package checksum is invalid.'); }
            file_put_contents($work . '/package.zip', $archive);
            unset($archive);
            self::extractVerified($work . '/package.zip', $work . '/verified', $manifest);
            $candidate = $work . '/verified/modules/registrars/gateway_srs';
            $version = json_decode(file_get_contents($candidate . '/version.json'), true, 512, JSON_THROW_ON_ERROR);
            if (($version['version'] ?? '') !== $manifest['version']) { throw new \RuntimeException('The package version does not match the signed manifest.'); }
            $free = disk_free_space(dirname($this->directory));
            if ($free !== false && $free < 100 * 1024 * 1024) { throw new \RuntimeException('At least 100 MB of free space is required to stage and back up the update.'); }
            Files::copy($candidate, $stage);
            $operationLock = $this->state->lock('operations');
            $current = json_decode(file_get_contents($this->directory . '/version.json'), true, 512, JSON_THROW_ON_ERROR);
            if (($current['version'] ?? '') !== $installed['version']) {
                throw new \RuntimeException('The installed module changed during download. Reload and check again.');
            }
            // The companion manager is a stable recovery bootstrap. Its own upgrades require a manual package install.
            if (($prior['phase'] ?? '') === 'installed' && !empty($prior['backup'])) {
                $this->assertBackupPath($prior['backup']);
                Files::remove($prior['backup']);
            }
            $journal = ['phase' => 'installing', 'target' => $this->directory,
                'backup' => dirname($this->directory) . '/.gateway_srs-backup-' . bin2hex(random_bytes(8)),
                'stage' => $stage, 'previous_version' => $installed['version'], 'version' => $manifest['version']];
            $this->state->write('update', $journal);
            try {
                if (!rename($this->directory, $journal['backup']) || !rename($stage, $this->directory)) {
                    throw new \RuntimeException('Cannot replace the module directory.');
                }
                Files::invalidate($this->directory);
                $journal['phase'] = 'installed';
                $this->state->write('update', $journal);
            } catch (\Throwable $e) {
                $this->restore($journal);
                throw new \RuntimeException('Installation failed. The previous module version was restored.', 0, $e);
            }
            SafeLog::write('Module updated', ['version' => $manifest['version'], 'event' => 'update_success']);
            return $manifest['version'];
        } finally {
            // A failed restore keeps its backup and journal for the independent admin recovery bootstrap.
            try {
                if (is_dir($work)) { Files::remove($work); }
                if (is_dir($stage)) { Files::remove($stage); }
            } catch (\Throwable $e) { SafeLog::write('Update cleanup failed', ['event' => 'cleanup_error']); }
            if (is_resource($operationLock)) { flock($operationLock, LOCK_UN); fclose($operationLock); }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function rollback(): void
    {
        $installer = $this->state->lock('installer');
        $lock = null;
        try {
            $lock = $this->state->lock('operations');
            $journal = $this->state->read('update');
            if (empty($journal['backup']) || !is_dir($journal['backup'])) { throw new \RuntimeException('There is no previous module backup to restore.'); }
            $this->restore($journal);
            SafeLog::write('Module rolled back', ['event' => 'rollback_success']);
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
            flock($installer, LOCK_UN); fclose($installer);
        }
    }

    private function assertBackupPath(string $path): void
    {
        if (dirname($path) !== dirname($this->directory) || !preg_match('/^\.gateway_srs-backup-[a-f0-9]{16}$/D', basename($path)) || is_link($path)) {
            throw new \RuntimeException('Invalid update backup path.');
        }
    }

    private function restore(array $journal): void
    {
        $this->assertBackupPath($journal['backup']);
        if (($journal['target'] ?? '') !== $this->directory || is_link($this->directory)) { throw new \RuntimeException('Invalid update target.'); }
        $journal['phase'] = 'recovering';
        $this->state->write('update', $journal);
        if (is_dir($journal['backup'])) {
            // Removal can be interrupted safely: the intact backup remains until its final rename.
            Files::remove($this->directory);
            if (!rename($journal['backup'], $this->directory)) { throw new \RuntimeException('Recovery could not restore the backup. Use the admin recovery action.'); }
        } elseif (!is_dir($this->directory)) {
            throw new \RuntimeException('The module and its backup are missing. Restore a manual backup.');
        }
        Files::invalidate($this->directory);
        $journal['phase'] = 'rolled_back';
        $this->state->write('update', $journal);
    }

    public static function verifyManifest(string $bytes, string $signature, string $publicKey): array
    {
        $decoded = base64_decode(trim($signature), true);
        if ($decoded === false || openssl_verify($bytes, $decoded, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('The update manifest signature is invalid.');
        }
        $data = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (($data['schema'] ?? null) !== 1 || ($data['module'] ?? '') !== 'gateway_srs'
            || !preg_match('/^\d+\.\d+\.\d+$/D', $data['version'] ?? '')
            || !preg_match('/^[a-f0-9]{64}$/D', $data['sha256'] ?? '') || empty($data['files']) || !is_array($data['files'])) {
            throw new \RuntimeException('The signed update manifest is malformed.');
        }
        return $data;
    }

    private function checkCompatibility(array $manifest): void
    {
        // WHMCS canonical versions include labels such as 8.6.0-release.1. Compare the numeric platform release.
        if (!preg_match('/^(\d+\.\d+\.\d+)/', $this->whmcsVersion, $parts)) {
            throw new \RuntimeException('Cannot determine the installed WHMCS version.');
        }
        $platformVersion = $parts[1];
        if (!isset($manifest['php_min'], $manifest['whmcs_min'], $manifest['manager_api'])
            || $manifest['manager_api'] !== 1 || version_compare(PHP_VERSION, $manifest['php_min'], '<')
            || version_compare($platformVersion, $manifest['whmcs_min'], '<')
            || (isset($manifest['php_max']) && version_compare(PHP_VERSION, $manifest['php_max'], '>'))
            || (isset($manifest['whmcs_max']) && version_compare($platformVersion, $manifest['whmcs_max'], '>'))) {
            throw new \RuntimeException('This release requires a different PHP, WHMCS or manager version. Review the release notes and update manually.');
        }
        foreach (['curl', 'json', 'iconv'] as $extension) {
            if (!extension_loaded($extension)) { throw new \RuntimeException('The release requires PHP ' . $extension . '.'); }
        }
    }

    public static function extractVerified(string $archive, string $destination, array $manifest): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) { throw new \RuntimeException('Cannot open the update ZIP.'); }
        try {
            if ($zip->numFiles > 500) { throw new \RuntimeException('The update ZIP contains too many entries.'); }
            $seen = [];
            $files = [];
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                if (!preg_match('#^modules/(registrars/gateway_srs|addons/gateway_srs_manager)/[A-Za-z0-9_./-]+$#D', $name)
                    || preg_match('#(^|/)[.]{1,2}(/|$)#', $name) || strpos($name, '//') !== false
                    || isset($seen[strtolower(rtrim($name, '/'))])) {
                    throw new \RuntimeException('The update ZIP contains an unsafe or duplicate path.');
                }
                $seen[strtolower(rtrim($name, '/'))] = true;
                $zip->getExternalAttributesIndex($i, $os, $attributes);
                $type = ($attributes >> 16) & 0170000;
                if ($type !== 0 && $type !== 0100000 && $type !== 0040000) { throw new \RuntimeException('The update ZIP contains a link or special file.'); }
                $size += $stat['size'];
                if ($size > 50 * 1024 * 1024 || $stat['size'] > 10 * 1024 * 1024) { throw new \RuntimeException('The expanded update ZIP exceeds its size limit.'); }
                if (substr($name, -1) !== '/') {
                    if (!isset($manifest['files'][$name]) || !preg_match('/^[a-f0-9]{64}$/D', $manifest['files'][$name])) {
                        throw new \RuntimeException('The ZIP file list does not match its signed manifest.');
                    }
                    $files[$name] = $stat['size'];
                }
            }
            $expected = array_keys($manifest['files']);
            $actual = array_keys($files);
            sort($expected); sort($actual);
            if ($expected !== $actual) { throw new \RuntimeException('The ZIP is missing signed package files.'); }
            foreach (['gateway_srs.php', 'lib/bootstrap.php', 'lib/DNSAPI.php', 'version.json', 'release-public-key.pem'] as $required) {
                if (!isset($files['modules/registrars/gateway_srs/' . $required])) { throw new \RuntimeException('The ZIP is missing a required module file.'); }
            }
            if (file_exists($destination) || !mkdir($destination, 0700, true)) { throw new \RuntimeException('Cannot create a private extraction directory.'); }
            foreach ($files as $name => $length) {
                $path = $destination . '/' . $name;
                if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) { throw new \RuntimeException('Cannot extract an update directory.'); }
                $in = $zip->getStream($name);
                $out = fopen($path, 'xb');
                if (!$in || !$out) { throw new \RuntimeException('Cannot extract an update file.'); }
                try {
                    if (stream_copy_to_stream($in, $out, $length + 1) !== $length) { throw new \RuntimeException('The ZIP entry length is invalid.'); }
                } finally { fclose($in); fclose($out); }
                chmod($path, 0600);
                if (!hash_equals($manifest['files'][$name], hash_file('sha256', $path))) { throw new \RuntimeException('An extracted update file failed verification.'); }
            }
        } finally { $zip->close(); }
    }
}
