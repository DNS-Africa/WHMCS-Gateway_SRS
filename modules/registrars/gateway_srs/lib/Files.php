<?php
namespace WHMCS\Module\Registrar\gateway_srs;

final class Files
{
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) { throw new \RuntimeException('Cannot remove an update file.'); }
        } elseif (is_dir($path)) {
            foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $file) {
                self::remove($file->getPathname());
            }
            if (!rmdir($path)) { throw new \RuntimeException('Cannot remove an update directory.'); }
        }
    }

    public static function copy(string $from, string $to): void
    {
        if (is_link($from)) { throw new \RuntimeException('Symlinks are not allowed in module packages.'); }
        if (is_dir($from)) {
            if (!is_dir($to) && !mkdir($to, 0755, true)) { throw new \RuntimeException('Cannot stage the module.'); }
            foreach (new \FilesystemIterator($from, \FilesystemIterator::SKIP_DOTS) as $file) {
                self::copy($file->getPathname(), $to . '/' . $file->getFilename());
            }
        } elseif (!copy($from, $to) || !chmod($to, 0644)) {
            throw new \RuntimeException('Cannot stage an update file.');
        }
    }

    public static function invalidate(string $directory): void
    {
        if (!function_exists('opcache_invalidate') || !is_dir($directory)) { return; }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') { opcache_invalidate($file->getPathname(), true); }
        }
    }
}
