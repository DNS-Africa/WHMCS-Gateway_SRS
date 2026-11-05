<?php
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }
require_once __DIR__ . '/lib/bootstrap.php';

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
});

add_hook('ClientAreaPrimarySidebar', 1, static function (MenuItem $sidebar) {
    $domain = Menu::context('domain');
    if (!$domain || ($domain->registrarModuleName ?? $domain->registrar ?? '') !== 'gateway_srs') { return; }
    if (in_array(ltrim($domain->tld, '.'), ['eu', 'nl', 'co.za'], true)) {
        $details = $sidebar->getChild('Domain Details Management');
        if ($details && $details->getChild('Registrar Lock Status')) { $details->removeChild('Registrar Lock Status'); }
    }
});

add_hook('ClientAreaPageDomainDetails', 1, static function ($vars) {
    $domain = Menu::context('domain');
    if (!$domain || ($domain->registrarModuleName ?? $domain->registrar ?? '') !== 'gateway_srs') { return []; }
    if (!in_array(ltrim($domain->tld, '.'), ['eu', 'nl', 'co.za'], true)) { return []; }
    $options = $vars['managementoptions'] ?? [];
    $options['locking'] = false;
    return ['managementoptions' => $options, 'lockstatus' => 'locked'];
});
