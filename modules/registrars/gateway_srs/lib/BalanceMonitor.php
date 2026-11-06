<?php
namespace WHMCS\Module\Registrar\gateway_srs;

final class BalanceMonitor
{
    private RuntimeState $state;
    private $fetch;
    private $notify;

    public function __construct(RuntimeState $state, callable $fetch, callable $notify)
    {
        $this->state = $state;
        $this->fetch = $fetch;
        $this->notify = $notify;
    }

    private function scope(array $settings): string
    {
        // Credentials are hashed, never persisted. Rotation cannot show another account's funds.
        $jwt = ($settings['Authentication'] ?? 'password') === 'jwt';
        return substr(hash('sha256', json_encode([$settings['AccountMode'] ?? 'live', $jwt,
            $jwt ? trim($settings['JWT_Token'] ?? '') : trim($settings['Portal_Username'] ?? ''),
            $jwt ? '' : ($settings['Portal_Password'] ?? '')])), 0, 32);
    }

    public static function thresholds(array $settings): ?array
    {
        if (empty($settings['Balance_Alerts'])) { return null; }
        $currency = Amount::currency(strtoupper(trim($settings['Balance_Currency'] ?? '')));
        $low = Amount::text(trim($settings['Balance_Low'] ?? ''));
        $critical = Amount::text(trim($settings['Balance_Critical'] ?? '0'));
        if (Amount::units($low) <= Amount::units($critical)) {
            throw new \InvalidArgumentException('The low-funds threshold must be greater than the critical threshold.');
        }
        return compact('currency', 'low', 'critical');
    }

    public function status(array $settings): array
    {
        $status = $this->state->read('balance-' . $this->scope($settings));
        if (!$status) { return ['level' => 'unknown', 'message' => 'Balance has not been checked. Use Check now or wait for the daily cron.']; }
        // Re-evaluate settings without waiting for cron; retain stale/error indicators.
        $status = $this->classify($status, $settings);
        $status['stale'] = ($status['checked_at'] ?? 0) < time() - 2 * 86400;
        return $status;
    }

    private function classify(array $status, array $settings): array
    {
        $status['level'] = 'unknown';
        $status['message'] = 'Balance is unavailable.';
        if (!isset($status['available'], $status['currency'])) { return $status; }
        $status['level'] = 'healthy';
        $status['message'] = 'Available credit is above the configured thresholds.';
        try {
            $limits = self::thresholds($settings);
            if (!$limits) { $status['message'] = 'Low-funds email alerts are disabled.'; return $status; }
            if ($limits['currency'] !== $status['currency']) {
                throw new \RuntimeException('The alert currency differs from the account currency. Update the registrar alert settings.');
            }
            $available = Amount::units($status['available'], true);
            $status['level'] = $available <= Amount::units($limits['critical']) ? 'critical'
                : ($available <= Amount::units($limits['low']) ? 'low' : 'healthy');
            $status['message'] = ['critical' => 'Available credit is critically low. Fund the account to keep registrar automation running.',
                'low' => 'Available credit is low. Fund the account before it runs out.',
                'healthy' => 'Available credit is above the configured thresholds.'][$status['level']];
        } catch (\Throwable $e) {
            $status['level'] = 'configuration';
            $status['message'] = $e->getMessage();
        }
        return $status;
    }

    public function run(array $settings): array
    {
        $scope = $this->scope($settings);
        $lock = $this->state->lock('balance-' . $scope);
        try {
            $status = $this->state->read('balance-' . $scope);
            try {
                $response = ($this->fetch)($settings);
                $status = ['currency' => Amount::currency($response['currency'] ?? null),
                    'balance' => Amount::text($response['balance'] ?? null, true),
                    'creditlimit' => Amount::text($response['creditlimit'] ?? null, true),
                    'available' => Amount::text($response['available'] ?? null, true), 'checked_at' => time()];
            } catch (\Throwable $e) {
                $status['error'] = 'The balance check failed. The values shown are from the last successful check; they may be out of date.';
            }
            $status['attempted_at'] = time();
            $this->state->write('balance-' . $scope, $status);
            $status = $this->classify($status, $settings);
            if (!empty($status['error']) || $status['level'] === 'unknown') { return $status; }
            try { $limits = self::thresholds($settings); }
            catch (\Throwable $e) { return $status; }
            if (!$limits) { return $status; }
            $key = 'balance-notifications-' . $scope;
            $sent = $this->state->read($key);
            $policy = hash('sha256', json_encode($limits));
            if (($sent['policy'] ?? '') !== $policy) { $sent = ['policy' => $policy, 'levels' => []]; }
            if ($status['level'] === 'healthy') {
                if (!empty($sent['levels']) && ($this->notify)($status + ['recovered' => true], $settings['AccountMode'] ?? 'live')) {
                    $this->state->write($key, ['policy' => $policy, 'levels' => []]);
                }
            } elseif (empty($sent['levels'][$status['level']]) && ($this->notify)($status, $settings['AccountMode'] ?? 'live')) {
                $sent['levels'][$status['level']] = time();
                $this->state->write($key, $sent);
            }
            return $status;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function panel(array $status): string
    {
        $e = [Module::class, 'escape'];
        $class = !empty($status['error']) || ($status['checked_at'] ?? 0) < time() - 2 * 86400 ? 'warning'
            : (in_array($status['level'], ['critical', 'configuration'], true) ? 'danger' : ($status['level'] === 'low' ? 'warning' : 'info'));
        $html = '<div class="panel panel-default"><div class="panel-heading"><strong>Gateway SRS account balance</strong></div><div class="panel-body">';
        if (isset($status['available'])) {
            foreach (['available' => 'Available credit', 'balance' => 'Account balance', 'creditlimit' => 'Credit limit'] as $key => $label) {
                $html .= '<p>' . $label . ': <strong>' . $e($status[$key]) . ' ' . $e($status['currency']) . '</strong></p>';
            }
            $html .= '<p>Available credit includes the credit facility. Last successful check: ' . gmdate('Y-m-d H:i:s', $status['checked_at']) . ' UTC.</p>';
        }
        $html .= '<div class="alert alert-' . $class . '">' . $e($status['message']);
        if (!empty($status['error'])) { $html .= '<br>' . $e($status['error']); }
        elseif (isset($status['checked_at']) && $status['checked_at'] < time() - 2 * 86400) { $html .= '<br>This balance is out of date. Run Check now and verify the daily cron.'; }
        return $html . '</div></div></div>';
    }
}
