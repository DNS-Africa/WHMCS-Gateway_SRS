<?php
namespace WHMCS\Module\Registrar\gateway_srs;

final class Lifecycle
{
    public static function expiry(array $info): int
    {
        $value = $info['expiry'] ?? null;
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2}))?$/D', $value)) {
            throw new \RuntimeException('Gateway SRS returned no valid domain expiry.');
        }
        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) { throw new \RuntimeException(); }
            return $date->getTimestamp();
        } catch (\Throwable $e) { throw new \RuntimeException('Gateway SRS returned no valid domain expiry.'); }
    }

    public static function codes(array $info): array
    {
        $codes = [];
        foreach (array_merge($info['statuses'] ?? [], $info['rgp_statuses'] ?? []) as $status) {
            $code = is_array($status) ? ($status['code'] ?? '') : $status;
            if (is_string($code) && $code !== '') { $codes[] = $code; }
        }
        return array_unique($codes);
    }

    public static function registrationStatus(array $info): string
    {
        $codes = self::codes($info);
        if (array_intersect(['redemptionPeriod', 'pendingRestore'], $codes)) { return 'Pending Delete Restorable'; }
        if (in_array('pendingDelete', $codes, true)) { return 'Expired'; }
        if (array_intersect(['clientHold', 'serverHold'], $codes)) { return 'Suspended'; }
        if (array_intersect(['inactive', 'pendingCreate'], $codes)) { return 'InActive'; }
        return self::expiry($info) <= time() ? 'Expired' : 'Active';
    }

    /** @param callable|null $missing Called when this account cannot see the domain and no other registrar is shown to own it. */
    public static function sync(DNSAPI $api, string $name, ?callable $missing = null): array
    {
        try {
            $info = $api->view_domain($name);
        } catch (RestAPIException $e) {
            if ($e->getCode() !== 404) { throw $e; }
            // Positive sponsoring-registrar evidence is required. Absence is never proof.
            try {
                $info = $api->registry_domain_info($name);
            } catch (\Throwable $lookup) {
                if ($missing) { $missing(); }
                throw $lookup;
            }
            if (isset($info['name']) && $info['name'] !== $name) {
                throw new \RuntimeException('Gateway SRS returned information for another domain.');
            }
            if (is_string($info['rar'] ?? null) && $info['rar'] !== '' && $info['rar'] !== $api->registrarId()) {
                return ['transferredAway' => true];
            }
            if ($missing) { $missing(); }
            throw new \RuntimeException('Domain ownership could not be confirmed; synchronization was left unchanged.');
        }
        $expiry = self::expiry($info);
        $codes = self::codes($info);
        $expired = $expiry <= time() || (bool) array_intersect(['redemptionPeriod', 'pendingRestore', 'pendingDelete'], $codes);
        $blocked = array_intersect(['clientHold', 'serverHold', 'inactive', 'pendingCreate', 'pendingTransfer', 'pendingDelete',
            'redemptionPeriod', 'pendingRestore'], $codes);
        // WHMCS Sync has no suspended/redemption return flag. Preserve those local states,
        // report accurate expiry, and expose their precise state via GetDomainInformation.
        return ['expirydate' => gmdate('Y-m-d', $expiry), 'active' => !$expired && !$blocked,
            'expired' => $expired, 'transferredAway' => false];
    }

    public static function transfer(DNSAPI $api, string $name): array
    {
        try {
            $response = $api->transfer_query($name);
        } catch (RestAPIException $e) {
            if (!in_array($e->getCode(), [404], true)) { throw $e; }
            return []; // No transfer history is not failure or completion.
        }
        $transfer = $response['results'] ?? [];
        if (isset($transfer[0])) { $transfer = $transfer[0]; }
        if (!is_array($transfer) || ($transfer['name'] ?? $name) !== $name) {
            throw new \RuntimeException('Gateway SRS returned an invalid transfer result.');
        }
        $status = $transfer['status'] ?? $transfer['trStatus'] ?? $transfer['transfer_status'] ?? '';
        if ($status === 'pending' || $status === '') { return []; }
        $requester = $transfer['reID'] ?? $transfer['requester'] ?? $transfer['requesting_registrar'] ?? null;
        if ($requester !== $api->registrarId()) { return []; }
        $failures = ['clientRejected' => 'The current registrar rejected the transfer.',
            'serverCancelled' => 'The registry cancelled the transfer.', 'clientCancelled' => 'The requesting registrar cancelled the transfer.'];
        if (isset($failures[$status])) {
            // An older failed transfer must not override a currently owned domain.
            if ($api->list_domains($name)) { return []; }
            return ['failed' => true, 'reason' => $failures[$status]];
        }
        if (!in_array($status, ['clientApproved', 'serverApproved'], true)) { return []; }
        $info = $api->view_domain($name); // Current ownership, not an old approved transfer, proves completion.
        if (isset($info['rar']) && $info['rar'] !== $api->registrarId()) { return []; }
        if (in_array('pendingTransfer', self::codes($info), true)) { return []; }
        return ['completed' => true, 'expirydate' => gmdate('Y-m-d', self::expiry($info))];
    }

    /** Days the registry expiry may run ahead of WHMCS's before a renewal is treated as already done (time zones, sync lag). */
    public const RENEWAL_DRIFT_DAYS = 30;

    /**
     * Stop a renewal the registry has probably already applied. A renewal that timed out may still have succeeded;
     * WHMCS then keeps the old expiry, and re-reading curExpDate live would renew a second time.
     */
    public static function renewalGuard(string $registryExpiry, ?string $whmcsExpiry): void
    {
        $registry = self::date($registryExpiry);
        $whmcs = $whmcsExpiry === null ? null : self::date($whmcsExpiry);
        if ($registry === null || $whmcs === null) { return; } // No usable WHMCS date (for example 0000-00-00): nothing to compare.
        if ($registry->getTimestamp() - $whmcs->getTimestamp() > self::RENEWAL_DRIFT_DAYS * 86400) {
            throw new \RuntimeException('The registry expiry date (' . $registry->format('Y-m-d') . ') is later than the WHMCS expiry date ('
                . $whmcs->format('Y-m-d') . '), so this domain may already have been renewed. The renewal was stopped.'
                . ' Run Sync for the domain, then retry only if a renewal is still needed.');
        }
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) || $m[1] === '0000-00-00') { return null; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1], new \DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d') === $m[1] ? $date : null;
    }

    /**
     * After a create whose outcome is unknown: true only when the registry shows the domain on this account.
     * registry-api's domain view lists only the caller's own domains, and rar is checked when present.
     */
    public static function ownedByAccount(DNSAPI $api, string $name): bool
    {
        try {
            $info = $api->view_domain($name);
        } catch (\Throwable $e) {
            return false;
        }
        return ($info['name'] ?? $name) === $name && (!isset($info['rar']) || $info['rar'] === $api->registrarId());
    }
}
