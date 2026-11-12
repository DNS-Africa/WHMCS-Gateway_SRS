<?php
namespace WHMCS\Module\Registrar\gateway_srs;

final class Module
{
    /** Gateway SRS accounts, balances and premium quotes operate in USD; operators are not asked for a currency. */
    public const CURRENCY = 'USD';

    private static $apiFactory;

    public static function api(array $params): DNSAPI
    {
        return self::$apiFactory ? (self::$apiFactory)($params) : new DNSAPI($params);
    }

    /** Dependency injection for consumers and offline integration tests; never set from request parameters. */
    public static function setApiFactory(?callable $factory): void { self::$apiFactory = $factory; }

    private static $expiryReader;

    /** Test seam for the WHMCS expiry lookup; never set from request parameters. */
    public static function setExpiryReader(?callable $reader): void { self::$expiryReader = $reader; }

    /** The expiry date WHMCS holds for a domain (Y-m-d), or null when the domain ID is unknown. */
    public static function whmcsExpiry(int $domainId): ?string
    {
        if ($domainId <= 0) { return null; }
        if (self::$expiryReader) { return (self::$expiryReader)($domainId); }
        $value = \WHMCS\Database\Capsule::table('tbldomains')->where('id', $domainId)->value('expirydate');
        return $value === null ? null : (string) $value;
    }

    private static $redirector;

    /** Test seam for redirects; never set from request parameters. */
    public static function setRedirector(?callable $redirector): void { self::$redirector = $redirector; }

    /** Send the browser to a relative client-area URL and end the request (303 so a refresh cannot re-POST). */
    public static function redirect(string $url): void
    {
        if (self::$redirector) { (self::$redirector)($url); return; }
        header('Location: ' . $url, true, 303);
        exit;
    }

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
        }, self::balanceMonitor());
    }

    public static function balanceMonitor(): BalanceMonitor
    {
        return new BalanceMonitor(new RuntimeState(), static fn (array $settings) => self::api($settings)->balances(),
            static function (array $status, string $environment): bool {
                $recovered = !empty($status['recovered']);
                $subject = ($status['level'] === 'critical' ? 'URGENT: ' : '') . 'Gateway SRS funds: '
                    . ($recovered ? 'recovered' : $status['level']) . ' (' . strtoupper($environment) . ')';
                $body = self::escape($status['message']);
                if (isset($status['available'])) {
                    $body .= '<br>Available credit: ' . self::escape($status['available'] . ' ' . $status['currency'])
                        . '. Checked ' . gmdate('Y-m-d H:i:s', $status['checked_at']) . ' UTC.';
                }
                $body .= '<br>Open Gateway SRS Manager to check the account balance. Alert thresholds are configured under Domain Registrars &gt; Gateway SRS.';
                $result = localAPI('SendAdminEmail', ['customsubject' => $subject, 'custommessage' => $body, 'type' => 'system']);
                return ($result['result'] ?? '') === 'success';
            });
    }

    /** Canonical WHMCS version, or '' when the application is not available (CLI tooling, tests). */
    public static function whmcsVersion(): string
    {
        try {
            return class_exists('\App') ? InstallationReport::cleanVersion(\App::getVersion()->getCanonical()) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    public static function installationReport(): InstallationReport
    {
        return new InstallationReport(new RuntimeState(),
            static function (array $settings, array $payload): void { self::api($settings)->reportInstallation($payload); },
            static fn (): string => self::whmcsVersion(),
            static function (?string $id = null): ?string {
                if ($id !== null) {
                    \WHMCS\Config\Setting::setValue(InstallationReport::SETTING, $id);
                    return $id;
                }
                return (string) \WHMCS\Config\Setting::getValue(InstallationReport::SETTING) ?: null;
            });
    }

    /**
     * Silent best-effort: the installation report must never change the cron run or settings save that triggered it,
     * and its failures (the endpoint may not be deployed yet) are only logged when Debug is on.
     */
    public static function reportInstallation(array $settings): void
    {
        try { self::installationReport()->run($settings); }
        catch (\Throwable $e) {
            if (!empty($settings['debug'])) { SafeLog::write('Installation report unavailable', ['event' => 'installation_report_error']); }
        }
    }

    public static function registryWatch(): RegistryWatch
    {
        return new RegistryWatch(new RuntimeState(), static function (array $ids): array {
            $rows = [];
            foreach (\WHMCS\Database\Capsule::table('tbldomains')->whereIn('id', $ids)->get(['id', 'domain', 'userid', 'status', 'registrar']) as $row) {
                $rows[(int) $row->id] = ['domain' => (string) $row->domain, 'userid' => (int) $row->userid,
                    'status' => (string) $row->status, 'registrar' => (string) $row->registrar];
            }
            return $rows;
        });
    }

    /** Best-effort: recording a registry 404 must never change the result of the registrar call that found it. */
    public static function registryMissing(array $params, string $domain): void
    {
        try { self::registryWatch()->missing($params, $domain, (int) ($params['domainid'] ?? 0)); }
        catch (\Throwable $e) { SafeLog::write('Registry watch unavailable', ['event' => 'registry_watch_error']); }
    }

    public static function registryFound(array $params, string $domain): void
    {
        try { self::registryWatch()->found($params, $domain); }
        catch (\Throwable $e) { SafeLog::write('Registry watch unavailable', ['event' => 'registry_watch_error']); }
    }

    public static function registryMissingFor(array $settings, string $domain): ?array
    {
        try { return self::registryWatch()->isMissing($settings, $domain); }
        catch (\Throwable $e) { return null; }
    }

    /** Daily cron: one digest email of domains newly reported missing by the registry. */
    public static function notifyRegistryMissing(array $settings): int
    {
        return self::registryWatch()->notify($settings, static function (array $entries, string $environment, int $total): bool {
            [$subject, $body] = self::registryDigest($entries, $environment, $total);
            $result = localAPI('SendAdminEmail', ['customsubject' => $subject, 'custommessage' => $body, 'type' => 'system']);
            return ($result['result'] ?? '') === 'success';
        });
    }

    /** @return array{0: string, 1: string} Subject and HTML body of the registry-404 digest. */
    public static function registryDigest(array $entries, string $environment, int $total): array
    {
        $count = count($entries);
        $plural = $count !== 1;
        $subject = 'Gateway SRS: ' . $count . ' domain' . ($plural ? 's' : '') . ' not found at the registry (' . strtoupper($environment) . ')';
        $body = 'The Gateway SRS API returned HTTP 404 (no domain record available to this account) in the '
            . self::escape(RegistryWatch::label($environment)) . ' environment for ' . ($plural ? 'these domains' : 'this domain')
            . '. Clients cannot manage ' . ($plural ? 'them' : 'it') . ' until this is resolved.<br><br>'
            . '<table border="1" cellpadding="4" cellspacing="0"><tr><th>Domain</th><th>WHMCS domain ID</th><th>Client ID</th><th>First seen (UTC)</th></tr>';
        foreach (array_slice($entries, 0, RegistryWatch::DIGEST_LIMIT) as $entry) {
            $body .= '<tr><td>' . self::escape($entry['domain']) . '</td><td>' . ((int) ($entry['domain_id'] ?? 0) ?: '-') . '</td><td>'
                . ((int) ($entry['userid'] ?? 0) ?: '-') . '</td><td>' . gmdate('Y-m-d H:i', (int) ($entry['first_seen'] ?? 0)) . '</td></tr>';
        }
        $body .= '</table>';
        if ($count > RegistryWatch::DIGEST_LIMIT) { $body .= '<br>And ' . ($count - RegistryWatch::DIGEST_LIMIT) . ' more.'; }
        $earlier = $total - $count;
        if ($earlier > 0) { $body .= '<br>' . $earlier . ' previously reported domain' . ($earlier === 1 ? ' is' : 's are') . ' still missing.'; }
        $body .= '<br><br>Likely causes: Account Mode is set to the wrong environment; the domain is not on this registrar account'
            . ' in the Gateway SRS portal; or the domain was transferred out or deleted.'
            . '<br>You will not be emailed about these domains again unless the registry finds them and they go missing again.';
        return [$subject, $body];
    }

    public static function registryNotice(array $settings): string
    {
        try { $entries = self::registryWatch()->entries($settings); }
        catch (\Throwable $e) { SafeLog::write('Registry watch unavailable', ['event' => 'registry_watch_error']); return ''; }
        if (!$entries) { return ''; }
        $count = count($entries);
        $links = [];
        foreach (array_slice($entries, 0, 5) as $entry) {
            $name = self::escape($entry['domain']);
            $links[] = !empty($entry['domain_id']) && !empty($entry['userid'])
                ? '<a href="clientsdomains.php?userid=' . (int) $entry['userid'] . '&amp;id=' . (int) $entry['domain_id'] . '">' . $name . '</a>'
                : $name;
        }
        return '<div class="alert alert-warning"><strong>Gateway SRS:</strong> ' . $count . ' domain' . ($count === 1 ? ' was' : 's were')
            . ' not found at the registry in the ' . self::escape(RegistryWatch::label(RegistryWatch::environment($settings)))
            . ' environment: ' . implode(', ', $links) . ($count > 5 ? ' and ' . ($count - 5) . ' more' : '')
            . '. Clients cannot manage ' . ($count === 1 ? 'it' : 'them') . '. Check Account Mode and the registrar assignment in the Gateway SRS portal.</div>';
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
            $balance = self::balanceMonitor()->status($settings);
            if (in_array($balance['level'], ['low', 'critical', 'configuration'], true) || !empty($balance['error']) || !empty($balance['stale'])) {
                $html .= '<div class="alert alert-' . ($balance['level'] === 'critical' ? 'danger' : 'warning')
                    . '"><strong>Gateway SRS funds:</strong> ' . self::escape($balance['message'])
                    . (!empty($balance['error']) ? ' ' . self::escape($balance['error']) : '')
                    . (!empty($balance['stale']) ? ' The balance is out of date. Run Check now and verify the daily cron.' : '')
                    . ' <a href="addonmodules.php?module=gateway_srs_manager">View account balance</a>.</div>';
            }
            if ($status && $status['level'] !== 'healthy') {
                $class = $status['level'] === 'warning' ? 'warning' : 'danger';
                $html .= '<div class="alert alert-' . $class . '"><strong>Gateway SRS:</strong> ' . self::escape($status['message']);
                if ($status['expiry']) {
                    $html .= ' Expiry: ' . gmdate('Y-m-d H:i:s', $status['expiry']) . ' UTC.';
                }
                $html .= '</div>';
            }
            $html .= self::registryNotice($settings);
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
