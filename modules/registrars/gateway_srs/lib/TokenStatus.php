<?php
namespace WHMCS\Module\Registrar\gateway_srs;

final class TokenStatus
{
    public static function inspect(string $token, ?int $now = null): array
    {
        $expiry = DNSAPI::jwtExpiry(trim($token));
        $remaining = $expiry === null ? null : $expiry - ($now ?? time());
        $level = $remaining === null ? 'invalid' : ($remaining <= 0 ? 'expired'
            : ($remaining <= 7 * 86400 ? 'critical' : ($remaining <= 30 * 86400 ? 'warning' : 'healthy')));
        $messages = [
            'invalid' => 'The Gateway SRS JWT is missing or has no valid expiry. Replace it with a portal-issued API-user token.',
            'expired' => 'The Gateway SRS JWT has expired. Registrar automation cannot authenticate. Replace the token now.',
            'critical' => 'Urgent: the Gateway SRS JWT expires within 7 days. Replace it to prevent registrar automation from stopping.',
            'warning' => 'The Gateway SRS JWT expires within 30 days. Obtain and save a replacement token from the portal.',
            'healthy' => 'The Gateway SRS JWT is within its validity period.',
        ];
        return ['level' => $level, 'expiry' => $expiry, 'days' => $remaining === null ? null : max(0, (int) ceil($remaining / 86400)),
            'message' => $messages[$level], 'fingerprint' => hash('sha256', trim($token))];
    }

    public static function notificationKey(array $status, string $environment): string
    {
        return hash('sha256', $environment . ':' . $status['fingerprint'] . ':' . $status['level']);
    }
}
