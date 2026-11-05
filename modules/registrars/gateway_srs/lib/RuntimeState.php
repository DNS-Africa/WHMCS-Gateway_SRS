<?php
namespace WHMCS\Module\Registrar\gateway_srs;

final class RuntimeState
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $root = defined('ROOTDIR') ? ROOTDIR : dirname(__DIR__, 5);
        $this->directory = $directory ?? (defined('GATEWAY_SRS_STATE_DIR')
            ? GATEWAY_SRS_STATE_DIR : sys_get_temp_dir() . '/gateway-srs-' . substr(hash('sha256', $root), 0, 20));
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create the private Gateway SRS state directory.');
        }
        if (is_link($this->directory) || (fileperms($this->directory) & 0777) !== 0700
            || (function_exists('posix_geteuid') && fileowner($this->directory) !== posix_geteuid())) {
            throw new \RuntimeException('Gateway SRS state directory must be owned by the PHP user with permissions 0700.');
        }
    }

    public function path(string $name): string
    {
        if (!preg_match('/^[a-z0-9_-]+$/D', $name)) {
            throw new \InvalidArgumentException('Invalid state name.');
        }
        return $this->directory . '/' . $name;
    }

    public function read(string $name): array
    {
        $path = $this->path($name) . '.json';
        if (is_link($path)) {
            throw new \RuntimeException('Unsafe state file.');
        }
        $data = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        return is_array($data) ? $data : [];
    }

    public function write(string $name, array $data): void
    {
        $path = $this->path($name) . '.json';
        $temp = tempnam($this->directory, 'state-');
        try {
            if ($temp === false || file_put_contents($temp, json_encode($data, JSON_THROW_ON_ERROR)) === false
                || !chmod($temp, 0600) || !rename($temp, $path)) {
                throw new \RuntimeException('Cannot save Gateway SRS state.');
            }
        } finally {
            if ($temp && is_file($temp)) {
                unlink($temp);
            }
        }
    }

    public function remove(string $name): void
    {
        $path = $this->path($name) . '.json';
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Cannot remove Gateway SRS state.');
        }
    }

    public function lock(string $name, bool $exclusive = true)
    {
        $path = $this->path($name) . '.lock';
        if (is_link($path)) {
            throw new \RuntimeException('Unsafe lock file.');
        }
        $handle = fopen($path, 'c');
        if (!$handle || !chmod($path, 0600) || !flock($handle, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('Gateway SRS is busy. Retry after the current operation or update finishes.');
        }
        return $handle;
    }
}
