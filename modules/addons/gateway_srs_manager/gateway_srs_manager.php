<?php
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

require_once __DIR__ . '/Recovery.php';

function gateway_srs_manager_config()
{
    return ['name' => 'Gateway SRS Manager', 'description' => 'JWT status, release notifications, signed registrar updates and recovery.',
        'version' => '1.0.0', 'author' => 'DNS Africa', 'language' => 'english'];
}

function gateway_srs_manager_activate() { return ['status' => 'success', 'description' => 'Grant access to the appropriate administrator roles.']; }
function gateway_srs_manager_deactivate() { return ['status' => 'success', 'description' => 'Registrar settings and monitoring are preserved.']; }

function gateway_srs_manager_output($vars)
{
    $escape = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $admin = (new \WHMCS\Authentication\CurrentUser())->admin();
    if (!$admin || !$admin->hasPermission('Configure Domain Registrars')) {
        echo '<div class="alert alert-danger">The Configure Domain Registrars permission is required.</div>';
        return;
    }
    $root = ROOTDIR;
    $directory = $root . '/modules/registrars/gateway_srs';
    if (empty($_SESSION['gateway_srs_manager_csrf'])) { $_SESSION['gateway_srs_manager_csrf'] = bin2hex(random_bytes(32)); }
    $nonce = $_SESSION['gateway_srs_manager_csrf'];
    $form = static function (string $action, string $label, string $class = 'default') use ($vars, $nonce, $escape) {
        return '<form method="post" action="' . $escape($vars['modulelink']) . '" style="display:inline-block;margin-right:8px">'
            . generate_token('form')
            . '<input type="hidden" name="gateway_srs_csrf" value="' . $escape($nonce) . '">'
            . '<input type="hidden" name="gateway_srs_action" value="' . $escape($action) . '">'
            . '<button type="submit" class="btn btn-' . $class . '">' . $escape($label) . '</button></form>';
    };
    try {
        $journal = \GatewaySrsManager\Recovery::journal($root);
        $pending = in_array($journal['phase'] ?? '', ['installing', 'recovering'], true);
        $action = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? ($_POST['gateway_srs_action'] ?? '') : '';
        if ($action !== '') {
            if (!is_string($_POST['gateway_srs_csrf'] ?? null) || !hash_equals($nonce, $_POST['gateway_srs_csrf'])) {
                throw new \RuntimeException('The security token is invalid. Reload this page and try again.');
            }
            if ($action === 'recover') {
                \GatewaySrsManager\Recovery::recover($root);
                echo '<div class="alert alert-success">The previous module has been restored. Reload this page before continuing.</div>';
                return;
            }
            if ($pending) { throw new \RuntimeException('Recover the interrupted update first.'); }
        }
        if ($pending || !is_file($directory . '/lib/bootstrap.php')) {
            echo '<div class="alert alert-danger">A registrar update was interrupted. Recover the previous version before using the module.</div>';
            echo $form('recover', 'Recover previous module', 'danger');
            return;
        }
        require_once $directory . '/lib/bootstrap.php';
        // Load installer helpers before replacing their source directory.
        class_exists(\WHMCS\Module\Registrar\gateway_srs\Files::class);
        class_exists(\WHMCS\Module\Registrar\gateway_srs\SafeLog::class);
        $module = \WHMCS\Module\Registrar\gateway_srs\Module::class;
        $state = new \WHMCS\Module\Registrar\gateway_srs\RuntimeState();
        $monitor = $module::monitor();
        $settings = $module::settings();
        $whmcsVersion = (string) \App::getVersion()->getCanonical();
        $updater = new \WHMCS\Module\Registrar\gateway_srs\Updater($directory, $state,
            new \WHMCS\Module\Registrar\gateway_srs\ReleaseClient(), $whmcsVersion);
        if ($action === 'check') {
            $monitor->checkRelease(true);
            if ($settings) { $monitor->run($settings); }
        } elseif ($action === 'install') {
            $version = $updater->install();
            echo '<div class="alert alert-success">Installed Gateway SRS ' . $escape($version) . '. Reload this page before continuing.</div>';
            return;
        } elseif ($action === 'rollback') {
            $updater->rollback();
            echo '<div class="alert alert-success">The previous module was restored. Reload this page before continuing.</div>';
            return;
        } elseif ($action !== '') { throw new \RuntimeException('Unknown module action.'); }
        $local = $module::version();
        echo '<h2>Gateway SRS</h2><p>Installed registrar version: <strong>' . $escape($local['version']) . '</strong></p>';
        if (!$settings) { echo '<p>Activate the Gateway SRS registrar and configure its credentials to enable monitoring.</p>'; }
        $token = $monitor->tokenStatus($settings);
        if ($token) {
            $class = $token['level'] === 'healthy' ? 'success' : ($token['level'] === 'warning' ? 'warning' : 'danger');
            echo '<div class="alert alert-' . $class . '">' . $escape($token['message']);
            if ($token['expiry']) { echo '<br>Expiry: ' . gmdate('Y-m-d H:i:s', $token['expiry']) . ' UTC (' . $escape($token['days']) . ' days remaining).'; }
            echo '</div><p>Replace tokens in the registrar configuration. Portal reroll invalidates the old token immediately; update every installation using it.</p>';
        } elseif ($settings) { echo '<p>Authentication: portal username and password.</p>'; }
        $release = $state->read('release');
        echo '<p>Latest published version: ' . $escape($release['version'] ?? 'not checked') . '</p>';
        if (!empty($release['checked_at'])) { echo '<p>Last successful check: ' . gmdate('Y-m-d H:i:s', $release['checked_at']) . ' UTC.</p>'; }
        if (!empty($release['error'])) { echo '<div class="alert alert-warning">' . $escape($release['error']) . '</div>'; }
        if (!empty($release['notes'])) { echo '<h3>Release notes</h3><pre style="white-space:pre-wrap">' . $escape($release['notes']) . '</pre>'; }
        echo $form('check', 'Check now');
        $problem = $updater->availability();
        $newer = !empty($release['version']) && version_compare($release['version'], $local['version'], '>');
        $signed = isset($release['assets']['gateway_srs.zip'], $release['assets']['release-manifest.json'], $release['assets']['release-manifest.sig']);
        if ($newer && $signed && $problem === null) { echo $form('install', 'Install latest stable release', 'primary'); }
        if (($journal['phase'] ?? '') === 'installed' && is_dir($journal['backup'] ?? '')) { echo $form('rollback', 'Restore previous version', 'warning'); }
        if ($problem !== null) { echo '<p>' . $escape($problem) . '</p>'; }
        if ($newer && !$signed) { echo '<p>This release requires a manual update because it has no signed update package.</p>'; }
        echo '<p><a href="https://github.com/DNS-Africa/WHMCS-Gateway_SRS/releases" target="_blank" rel="noopener">Release notes and manual downloads</a></p>';
    } catch (\Throwable $e) {
        echo '<div class="alert alert-danger">' . $escape($e->getMessage()) . '</div>';
    }
}
