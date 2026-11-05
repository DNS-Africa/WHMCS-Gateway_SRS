<?php
namespace WHMCS\Module\Registrar\gateway_srs;

final class Module
{
    private static $apiFactory;

    public static function api(array $params): DNSAPI
    {
        return self::$apiFactory ? (self::$apiFactory)($params) : new DNSAPI($params);
    }

    /** Dependency injection for consumers and offline integration tests; never set from request parameters. */
    public static function setApiFactory(?callable $factory): void { self::$apiFactory = $factory; }

    public static function version(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/version.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function settings(): array
    {
        $module = new \WHMCS\Module\Registrar();
        if (!$module->isActive('gateway_srs') || !$module->load('gateway_srs')) {
            return [];
        }
        return $module->getSettings();
    }

    public static function canManage(): bool
    {
        $admin = (new \WHMCS\Authentication\CurrentUser())->admin();
        return $admin && $admin->hasPermission('Configure Domain Registrars');
    }

    public static function monitor(): Monitor
    {
        return new Monitor(new RuntimeState(), new ReleaseClient(), static function (array $settings) {
            self::api($settings)->validateConnection();
        }, static function (array $status, string $environment): bool {
            $urgent = $status['level'] !== 'warning';
            $subject = ($urgent ? 'URGENT: ' : '') . 'Gateway SRS JWT: ' . $status['level'] . ' (' . strtoupper($environment) . ')';
            $body = self::escape($status['message']);
            if ($status['expiry']) {
                $body .= '<br>Expiry: ' . gmdate('Y-m-d H:i:s', $status['expiry']) . ' UTC.';
            }
            $body .= '<br>Replace the token in Configuration &gt; System Settings &gt; Domain Registrars &gt; Gateway SRS.'
                . '<br>Portal token reroll invalidates the old token immediately. Update every installation using that token.';
            $result = localAPI('SendAdminEmail', ['customsubject' => $subject, 'custommessage' => $body, 'type' => 'system']);
            return ($result['result'] ?? '') === 'success';
        });
    }

    public static function escape($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function adminNotice(): string
    {
        if (!self::canManage()) {
            return '';
        }
        try {
            $settings = self::settings();
            if (!$settings) { return ''; }
            $status = self::monitor()->tokenStatus($settings);
            $html = '';
            if ($status && $status['level'] !== 'healthy') {
                $class = $status['level'] === 'warning' ? 'warning' : 'danger';
                $html .= '<div class="alert alert-' . $class . '"><strong>Gateway SRS:</strong> ' . self::escape($status['message']);
                if ($status['expiry']) {
                    $html .= ' Expiry: ' . gmdate('Y-m-d H:i:s', $status['expiry']) . ' UTC.';
                }
                $html .= '</div>';
            }
            $release = (new RuntimeState())->read('release');
            if (!empty($release['version']) && version_compare($release['version'], self::version()['version'], '>')) {
                $html .= '<div class="alert alert-info">Gateway SRS ' . self::escape($release['version'])
                    . ' is available. <a href="addonmodules.php?module=gateway_srs_manager">View module updates</a>'
                    . ' or <a href="' . ReleaseClient::RELEASES_URL . '" target="_blank" rel="noopener">download the release</a>.</div>';
            }
            return $html;
        } catch (\Throwable $e) {
            SafeLog::write('Status unavailable', ['event' => 'status_error']);
            return '<div class="alert alert-warning">Gateway SRS monitoring is unavailable. Check the private state directory configuration.</div>';
        }
    }
}
