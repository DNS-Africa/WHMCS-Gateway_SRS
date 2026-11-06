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
            // Use WHMCS's own delegated-user permission enforcement on this native client route.
            if (!function_exists('checkContactPermission')) { throw new DnssecException('WHMCS domain permission checks are unavailable.'); }
            checkContactPermission('managedomains');
            $row = self::domain($params['domainid'] ?? null);
            if ((int) $row->userid !== (int) $client->id) { throw new DnssecException('You do not have access to this domain.'); }
            $url = 'clientarea.php?action=domaindetails&domainid=' . (int) $row->id . '&modop=custom&a=DNSSEC';
            return self::render($params, $row->domain, (int) $row->id, $url, 'client-' . $client->id);
        } catch (\Throwable $e) { return self::error($e); }
    }

    public static function admin(array $settings, string $moduleLink): string
    {
        try {
            if (!Module::canManage()) { throw new DnssecException('The Configure Domain Registrars permission is required.'); }
            $row = self::domain($_GET['domainid'] ?? null);
            $url = $moduleLink . '&domainid=' . (int) $row->id;
            $admin = (new \WHMCS\Authentication\CurrentUser())->admin();
            return self::render($settings, $row->domain, (int) $row->id, $url, 'admin-' . $admin->id);
        } catch (\Throwable $e) { return self::error($e); }
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

    private static function render(array $settings, string $name, int $id, string $url, string $actor): string
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
        $form = static function (string $action) use ($e, $url, $nonce, $records): string {
            return '<form method="post" action="' . $e($url) . '">' . generate_token('form')
                . '<input type="hidden" name="gateway_srs_dnssec_csrf" value="' . $e($nonce) . '">'
                . '<input type="hidden" name="gateway_srs_dnssec_action" value="' . $e($action) . '">'
                . '<input type="hidden" name="revision" value="' . Dnssec::revision($records) . '">';
        };
        $html = '<div class="panel panel-default"><div class="panel-heading"><strong>DNSSEC — ' . $e($name) . '</strong></div><div class="panel-body">'
            . '<p>Publish DS records supplied by your DNS hosting provider. Incorrect DS records can make your domain unreachable. '
            . 'Keep the old DS record during a key rollover until your provider says it is safe to remove it. Removing the last record disables the chain of trust.</p>' . $notice;
        if (!$records) { $html .= '<p>No DS records are published.</p>'; }
        else {
            $html .= '<div class="table-responsive"><table class="table"><thead><tr><th>Key tag</th><th>Algorithm</th><th>Digest type</th><th>Digest</th><th>Action</th></tr></thead><tbody>';
            foreach ($records as $record) {
                $html .= '<tr><td>' . $record['key_tag'] . '</td><td>' . $record['algorithm'] . '</td><td>' . $record['digest_type']
                    . '</td><td style="overflow-wrap:anywhere">' . $e($record['digest']) . '</td><td>' . $form('remove')
                    . '<input type="hidden" name="record_id" value="' . Dnssec::id($record) . '">'
                    . '<button class="btn btn-danger btn-sm" type="submit">' . (count($records) === 1 ? 'Remove last DS record' : 'Remove DS record') . '</button></form></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        $html .= '<h4>Add DS record</h4>' . $form('add')
            . '<div class="form-group"><label>Key tag <input class="form-control" name="key_tag" type="number" min="0" max="65535" required></label></div>'
            . '<div class="form-group"><label>Algorithm <select class="form-control" name="algorithm">';
        foreach ([8 => 'RSA/SHA-256', 10 => 'RSA/SHA-512', 13 => 'ECDSA P-256', 14 => 'ECDSA P-384', 15 => 'Ed25519', 16 => 'Ed448'] as $number => $label) {
            $html .= '<option value="' . $number . '">' . $number . ' — ' . $label . '</option>';
        }
        return $html . '</select></label></div><div class="form-group"><label>Digest type <select class="form-control" name="digest_type">'
            . '<option value="2">2 — SHA-256</option><option value="4">4 — SHA-384</option></select></label></div>'
            . '<div class="form-group"><label>Digest <textarea class="form-control" name="digest" rows="3" maxlength="200" required></textarea></label></div>'
            . '<button class="btn btn-primary" type="submit">Add DS record</button></form></div></div>';
    }
}
