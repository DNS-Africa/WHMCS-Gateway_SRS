<?php
namespace WHMCS\Module\Registrar\gateway_srs;

use WHMCS\Database\Capsule;

final class DnssecPage
{
    private static function domain($id): object
    {
        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new DnssecException('A valid domain is required.');
        }
        $row = Capsule::table('tbldomains')->where('id', (int) $id)->where('registrar', 'gateway_srs')->first();
        if (!$row) { throw new DnssecException('This domain is not managed by Gateway SRS.'); }
        return $row;
    }

    public static function client(array $params): string
    {
        try {
            $client = (new \WHMCS\Authentication\CurrentUser())->client();
            if (!$client) { throw new DnssecException('Sign in to manage DNSSEC.'); }
            // The same check WHMCS core runs (8.12 through 9.1), covering owners, delegated users and admins using
            // "Login as Client". Pass $noRedirect = true: without it a failed check redirects and exits.
            // (WHMCS\User\Client::hasPermission() is unimplemented in 8.12, so the model API is not an option.)
            if (!function_exists('checkContactPermission')) { throw new DnssecException('WHMCS domain permission checks are unavailable.'); }
            if (!checkContactPermission('managedomains', true)) { throw new DnssecException('You do not have permission to manage domains.'); }
            $row = self::domain($params['domainid'] ?? null);
            if ((int) $row->userid !== (int) $client->id) { throw new DnssecException('You do not have access to this domain.'); }
            $url = 'clientarea.php?action=domaindetails&id=' . (int) $row->id . '&modop=custom&a=DNSSEC';
            return self::render($params, $row->domain, (int) $row->id, $url, 'client-' . $client->id, true);
        } catch (\Throwable $e) { return self::section(true, 'DNSSEC', self::error($e)); }
    }

    /**
     * Client-area link to the DNSSEC page. WHMCS 9.1 CSRF-checks every modop=custom request and, on a missing
     * token, destroys the whole PHP session (admins using "Login as Client" included). Its own sidebar link
     * for registrar custom buttons carries no token, so every link to this page must add one.
     */
    public static function link(int $domainId): string
    {
        return 'clientarea.php?action=domaindetails&id=' . $domainId . '&modop=custom&a=DNSSEC&token='
            . rawurlencode((string) generate_token('plain'));
    }

    public static function admin(array $settings, string $moduleLink): string
    {
        try {
            if (!Module::canManage()) { throw new DnssecException('The Configure Domain Registrars permission is required.'); }
            $row = self::domain($_GET['domainid'] ?? null);
            $url = $moduleLink . '&domainid=' . (int) $row->id;
            $admin = (new \WHMCS\Authentication\CurrentUser())->admin();
            return self::render($settings, $row->domain, (int) $row->id, $url, 'admin-' . $admin->id, false);
        } catch (\Throwable $e) { return self::section(false, 'DNSSEC', self::error($e)); }
    }

    private static function error(\Throwable $e): string
    {
        if (!$e instanceof DnssecException && !$e instanceof RestAPIException) {
            SafeLog::write('DNSSEC page failed', ['event' => 'dnssec_error']);
            return '<div class="alert alert-danger">DNSSEC could not be checked. Try again or contact support.</div>';
        }
        $message = $e instanceof RestAPIException && in_array($e->getCode(), [404, 405, 501], true)
            ? 'DNSSEC is unavailable for this domain, registry or API account. No change was confirmed.' : $e->getMessage();
        return '<div class="alert alert-danger">' . Module::escape($message) . '</div>';
    }

    private const ALGORITHMS = [8 => 'RSA/SHA-256', 10 => 'RSA/SHA-512', 13 => 'ECDSA P-256', 14 => 'ECDSA P-384', 15 => 'Ed25519', 16 => 'Ed448'];
    private const DIGEST_TYPES = [2 => 'SHA-256', 4 => 'SHA-384'];

    /** Client area: the theme's Bootstrap 4 tab-section markup. Admin area: Bootstrap 3 panels. */
    private static function section(bool $client, string $title, string $body): string
    {
        return $client
            ? '<h3 class="card-title">' . $title . '</h3><div class="card tt-custom-card"><div class="card-body">' . $body . '</div></div>'
            : '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title">' . $title . '</h3></div>'
                . '<div class="panel-body">' . $body . '</div></div>';
    }

    private static function field(bool $client, string $id, string $label, string $control, string $help = ''): string
    {
        $help = $help === '' ? '' : '<small class="form-text text-muted help-block">' . $help . '</small>';
        return $client
            ? '<div class="form-group row"><label for="' . $id . '" class="col-sm-4 col-form-label">' . $label . '</label>'
                . '<div class="col-md-7">' . $control . $help . '</div></div>'
            : '<div class="form-group"><label for="' . $id . '" class="col-sm-3 control-label">' . $label . '</label>'
                . '<div class="col-sm-6">' . $control . $help . '</div></div>';
    }

    private static function render(array $settings, string $name, int $id, string $url, string $actor, bool $client): string
    {
        $key = hash('sha256', $actor . ':' . $id);
        if (empty($_SESSION['gateway_srs_dnssec'][$key])) { $_SESSION['gateway_srs_dnssec'][$key] = bin2hex(random_bytes(32)); }
        $nonce = $_SESSION['gateway_srs_dnssec'][$key];
        $action = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? ($_POST['gateway_srs_dnssec_action'] ?? '') : '';
        if ($action !== '' && (!is_string($_POST['gateway_srs_dnssec_csrf'] ?? null)
            || !hash_equals($nonce, $_POST['gateway_srs_dnssec_csrf']))) {
            throw new DnssecException('The security token is invalid. Reload this page and try again.');
        }
        $api = Module::api($settings);
        $notice = '';
        if ($action !== '') {
            try {
                $records = Dnssec::change($api, $name, $action, $_POST);
                // Rotation prevents browser refresh from replaying the last mutation.
                $_SESSION['gateway_srs_dnssec'][$key] = $nonce = bin2hex(random_bytes(32));
                $notice = '<div class="alert alert-success">DNSSEC records updated and confirmed by the registry.</div>';
            } catch (\Throwable $e) { $notice = self::error($e); $records = Dnssec::records($api->dnssec($name)); }
        } else { $records = Dnssec::records($api->dnssec($name)); }
        $e = [Module::class, 'escape'];
        $form = static function (string $action, string $class = '') use ($e, $url, $nonce, $records): string {
            return '<form method="post" action="' . $e($url) . '"' . ($class !== '' ? ' class="' . $class . '"' : '') . '>' . generate_token('form')
                . '<input type="hidden" name="gateway_srs_dnssec_csrf" value="' . $e($nonce) . '">'
                . '<input type="hidden" name="gateway_srs_dnssec_action" value="' . $e($action) . '">'
                . '<input type="hidden" name="revision" value="' . Dnssec::revision($records) . '">';
        };
        $named = static fn (array $names, int $number): string => $number . (isset($names[$number]) ? ' — ' . $names[$number] : '');

        $body = $notice . '<div class="alert alert-info">Publish the DS records supplied by your DNS hosting provider. '
            . 'Incorrect DS records can make your domain unreachable. During a key rollover, keep the old record until your provider '
            . 'says it is safe to remove it. Removing the last record turns off DNSSEC validation for this domain.</div>'
            . '<h4 class="h6 mb-3">Published DS records</h4>';
        if (!$records) {
            $body .= '<p class="text-muted mb-0">No DS records are published, so DNSSEC validation is not enabled for this domain.</p>';
        } else {
            $body .= '<div class="table-responsive"><table class="table table-striped mb-0"><thead><tr><th>Key tag</th><th>Algorithm</th>'
                . '<th>Digest type</th><th>Digest</th><th class="text-right"><span class="sr-only">Action</span></th></tr></thead><tbody>';
            foreach ($records as $record) {
                $body .= '<tr><td>' . (int) $record['key_tag'] . '</td><td class="text-nowrap">' . $e($named(self::ALGORITHMS, (int) $record['algorithm']))
                    . '</td><td class="text-nowrap">' . $e($named(self::DIGEST_TYPES, (int) $record['digest_type']))
                    . '</td><td><code style="overflow-wrap:anywhere;word-break:break-all">' . $e($record['digest']) . '</code></td>'
                    . '<td class="text-right">' . $form('remove', 'mb-0')
                    . '<input type="hidden" name="record_id" value="' . Dnssec::id($record) . '">'
                    . '<button class="btn btn-danger btn-sm text-nowrap" type="submit">'
                    . (count($records) === 1 ? 'Remove last DS record' : 'Remove DS record') . '</button></form></td></tr>';
            }
            $body .= '</tbody></table></div>';
        }

        $options = static function (array $names): string {
            $html = '';
            foreach ($names as $number => $label) { $html .= '<option value="' . $number . '">' . $number . ' — ' . $label . '</option>'; }
            return $html;
        };
        $add = $form('add', $client ? '' : 'form-horizontal')
            . self::field($client, 'gatewaySrsDsKeyTag', 'Key tag',
                '<input class="form-control" id="gatewaySrsDsKeyTag" name="key_tag" type="number" inputmode="numeric" min="0" max="65535" required>',
                'A number from 0 to 65535, shown in your provider\'s DS record.')
            . self::field($client, 'gatewaySrsDsAlgorithm', 'Algorithm',
                '<select class="form-control" id="gatewaySrsDsAlgorithm" name="algorithm">' . $options(self::ALGORITHMS) . '</select>')
            . self::field($client, 'gatewaySrsDsDigestType', 'Digest type',
                '<select class="form-control" id="gatewaySrsDsDigestType" name="digest_type">' . $options(self::DIGEST_TYPES) . '</select>')
            . self::field($client, 'gatewaySrsDsDigest', 'Digest',
                '<textarea class="form-control" id="gatewaySrsDsDigest" name="digest" rows="3" maxlength="200" required spellcheck="false"'
                . ' autocomplete="off" style="font-family:SFMono-Regular,Menlo,Consolas,monospace"></textarea>',
                'Hexadecimal: 64 characters for SHA-256 or 96 for SHA-384. Spaces are ignored.')
            . ($client
                ? '<div class="row"><div class="col-sm-8 offset-sm-4"><button class="btn btn-primary" type="submit">Add DS record</button></div></div>'
                : '<div class="form-group mb-0"><div class="col-sm-offset-3 col-sm-6"><button class="btn btn-primary" type="submit">Add DS record</button></div></div>')
            . '</form>';

        return self::section($client, $client ? 'DNSSEC' : 'DNSSEC — ' . $e($name), $body)
            . self::section($client, 'Add DS record', $add);
    }
}
