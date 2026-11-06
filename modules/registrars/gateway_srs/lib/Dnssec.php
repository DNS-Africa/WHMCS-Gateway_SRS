<?php
namespace WHMCS\Module\Registrar\gateway_srs;

final class Dnssec
{
    public static function record(array $input, bool $new = true): array
    {
        $out = [];
        foreach (['key_tag' => 65535, 'algorithm' => 255, 'digest_type' => 255] as $field => $max) {
            $value = $input[$field] ?? null;
            if ((!is_int($value) && !is_string($value)) || !preg_match('/^\d{1,5}$/D', (string) $value)
                || (int) $value > $max || ($field !== 'key_tag' && (int) $value === 0)) {
                throw new DnssecException('Invalid DNSSEC ' . str_replace('_', ' ', $field) . '.');
            }
            $out[$field] = (int) $value;
        }
        if ($new && !in_array($out['algorithm'], [8, 10, 13, 14, 15, 16], true)) {
            throw new DnssecException('Use a supported DNSSEC signing algorithm: 8, 10, 13, 14, 15 or 16.');
        }
        $lengths = [1 => 40, 2 => 64, 3 => 64, 4 => 96, 5 => 64, 6 => 64];
        $digest = $input['digest'] ?? null;
        if (!is_string($digest)) { throw new DnssecException('Invalid DNSSEC digest.'); }
        $digest = strtoupper(preg_replace('/\s+/', '', $digest));
        if (!isset($lengths[$out['digest_type']]) || strlen($digest) !== $lengths[$out['digest_type']]
            || !ctype_xdigit($digest) || ($new && !in_array($out['digest_type'], [2, 4], true))) {
            throw new DnssecException('Use a SHA-256 (64 hex characters) or SHA-384 (96 hex characters) DS digest.');
        }
        $out['digest'] = $digest;
        if (isset($input['max_sig_life'])) {
            if (filter_var($input['max_sig_life'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]) === false) {
                throw new DnssecException('Invalid DNSSEC maximum signature lifetime.');
            }
            if ((int) $input['max_sig_life'] > 0) { $out['max_sig_life'] = (int) $input['max_sig_life']; }
        }
        return $out;
    }

    public static function records(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            if (!is_array($record)) { throw new DnssecException('Gateway SRS returned an invalid DS record.'); }
            $value = self::record($record, false);
            $out[self::id($value)] = $value;
        }
        ksort($out);
        return array_values($out);
    }

    public static function id(array $record): string { return hash('sha256', json_encode($record, JSON_THROW_ON_ERROR)); }
    public static function revision(array $records): string { return self::id(self::records($records)); }

    public static function change(DNSAPI $api, string $name, string $action, array $input): array
    {
        if (!in_array($action, ['add', 'remove'], true)) { throw new DnssecException('Unknown DNSSEC action.'); }
        $new = $action === 'add' ? self::record($input) : null;
        $lock = (new RuntimeState())->lock('dnssec-' . substr(hash('sha256', $name), 0, 32));
        try {
            $records = self::records($api->dnssec($name));
            if (!is_string($input['revision'] ?? null) || !hash_equals(self::revision($records), $input['revision'])) {
                throw new DnssecException('DNSSEC records have changed. Reload this page before making another change.');
            }
            if ($action === 'add') {
                foreach ($records as $record) {
                    if ($record['key_tag'] === $new['key_tag'] && $record['algorithm'] === $new['algorithm']
                        && $record['digest_type'] === $new['digest_type'] && $record['digest'] === $new['digest']) {
                        throw new DnssecException('This DS record already exists.');
                    }
                }
                $records[] = $new;
            } else {
                $before = count($records);
                $records = array_values(array_filter($records, static fn ($r) => self::id($r) !== ($input['record_id'] ?? null)));
                if (count($records) !== $before - 1) { throw new DnssecException('Select an existing DS record to remove.'); }
            }
            $info = $api->view_domain($name);
            if (isset($info['domainsec']) && !hash_equals(self::revision($info['domainsec']), $input['revision'])) {
                throw new DnssecException('DNSSEC records have changed. Reload this page before making another change.');
            }
            if (!isset($info['hosts'], $info['contacts']) || !is_array($info['hosts']) || !is_array($info['contacts'])) {
                throw new DnssecException('Domain settings are incomplete. DNSSEC was not changed.');
            }
            $payload = ['name' => $name, 'domainsec' => $records, 'hosts' => $info['hosts'], 'contacts' => $info['contacts'],
                'period' => $info['period'] ?? 1, 'period_unit' => $info['period_unit'] ?? 'y'];
            if (isset($info['autorenew'])) { $payload['autorenew'] = $info['autorenew']; }
            $api->update_domain($payload, $name);
            $confirmed = self::records($api->dnssec($name));
            if (!hash_equals(self::revision($records), self::revision($confirmed))) {
                throw new DnssecException('The requested DNSSEC change is not yet confirmed. Reload and check the registry records before retrying.');
            }
            return $confirmed;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
