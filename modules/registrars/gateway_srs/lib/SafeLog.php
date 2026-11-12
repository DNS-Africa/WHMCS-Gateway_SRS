<?php
namespace WHMCS\Module\Registrar\gateway_srs;

final class SafeLog
{
    // Deliberately log metadata only. API bodies, URLs, headers and module settings can contain secrets.
    public static function write(string $action, array $metadata = []): void
    {
        $safe = array_intersect_key($metadata, array_flip(['method', 'status', 'retry', 'version', 'event', 'svtrid']));
        if (function_exists('logModuleCall')) {
            logModuleCall('gateway_srs', $action, $safe, []);
        }
    }
}
