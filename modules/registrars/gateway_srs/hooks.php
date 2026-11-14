<?php
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }
require_once __DIR__ . '/lib/bootstrap.php';

use WHMCS\Module\Registrar\gateway_srs\DnssecPage;
use WHMCS\Module\Registrar\gateway_srs\Module;
use WHMCS\Module\Registrar\gateway_srs\SafeLog;
use WHMCS\View\Menu\Item as MenuItem;

add_hook('AdminAreaHeaderOutput', 1, static function () {
    return Module::adminNotice();
});

add_hook('DailyCronJob', 1, static function () {
    try {
        $settings = Module::settings();
        if ($settings) { Module::monitor()->run($settings); }
    } catch (\Throwable $e) {
        SafeLog::write('Monitoring failed', ['event' => 'monitor_error']);
    }
    try {
        // One digest of domains the registry newly returned HTTP 404 for (found by domain sync and page views).
        $settings = Module::settings();
        if ($settings) { Module::notifyRegistryMissing($settings); }
    } catch (\Throwable $e) {
        SafeLog::write('Registry watch digest failed', ['event' => 'registry_watch_error']);
    }
    try {
        // Silent: at most once a day unless a reported version changes; failures are logged only with Debug on.
        $settings = Module::settings();
        if ($settings) { Module::reportInstallation($settings); }
    } catch (\Throwable $e) {
        // Module::settings() failing is already visible through the other cron tasks above.
    }
});

add_hook('ClientAreaPrimarySidebar', 1, static function (MenuItem $sidebar) {
    $domain = Menu::context('domain');
    if (!$domain || ($domain->registrarModuleName ?? $domain->registrar ?? '') !== 'gateway_srs') { return; }
    $details = $sidebar->getChild('Domain Details Management');
    if (!$details) { return; }
    // WHMCS builds this custom-button link without a CSRF token; following it destroys the session on 9.1.
    $dnssec = $details->getChild('DNSSEC');
    if ($dnssec) { $dnssec->setUri(DnssecPage::link((int) $domain->id)); }
    if (in_array(ltrim($domain->tld, '.'), ['eu', 'nl', 'co.za'], true) && $details->getChild('Registrar Lock Status')) {
        $details->removeChild('Registrar Lock Status');
    }
    // Hosting, email and other services on this domain, so the client can go straight to them.
    $services = Module::relatedServices((int) $domain->id);
    if (!$services) { return; }
    $panel = $sidebar->addChild('Gateway SRS Related Services', ['label' => 'Related Services', 'icon' => 'fa-cubes', 'order' => 20]);
    foreach ($services as $i => $service) {
        $label = $service['name'] . ($service['domain'] !== strtolower((string) ($domain->domain ?? '')) ? ' (' . $service['domain'] . ')' : '');
        $panel->addChild('related-service-' . $service['id'], ['label' => Module::escape($label),
            'uri' => 'clientarea.php?action=productdetails&id=' . $service['id'],
            'badge' => Module::escape($service['status']), 'order' => ($i + 1) * 10]);
    }
});

// Auth Code page: offer "Generate new code" only for Gateway SRS domains that support EPP codes.
add_hook('ClientAreaPageDomainEPPCode', 1, static function ($vars) {
    $domain = Menu::context('domain');
    if (!$domain || ($domain->registrarModuleName ?? $domain->registrar ?? '') !== 'gateway_srs') { return []; }
    if (in_array(ltrim($domain->tld, '.'), ['co.za', 'net.za', 'org.za', 'web.za'], true)) { return []; }
    return ['gatewaySrsNewEppCode' => ['domainid' => (int) $domain->id]];
});

add_hook('ClientAreaPageDomainDetails', 1, static function ($vars) {
    $domain = Menu::context('domain');
    if (!$domain || ($domain->registrarModuleName ?? $domain->registrar ?? '') !== 'gateway_srs') { return []; }
    $result = [];
    if (in_array(ltrim($domain->tld, '.'), ['eu', 'nl', 'co.za'], true)) {
        $options = $vars['managementoptions'] ?? [];
        $options['locking'] = false;
        $result = ['managementoptions' => $options, 'lockstatus' => 'locked'];
    }
    // The registry returned HTTP 404 for this domain (recorded by this page's domain lookup or the daily sync).
    $settings = Module::settings();
    if ($settings && Module::registryMissingFor($settings, (string) $domain->domain)) {
        $alerts = is_array($vars['alerts'] ?? null) ? $vars['alerts'] : [];
        $alerts[] = ['type' => 'warning', 'title' => 'This domain can\'t be managed right now',
            'description' => 'Please contact support so we can resolve this.'];
        $result['alerts'] = $alerts;
    }
    return $result;
});
