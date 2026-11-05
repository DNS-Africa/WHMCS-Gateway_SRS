<?php
namespace WHMCS\Module\Registrar\gateway_srs;

final class Monitor
{
    private RuntimeState $state;
    private ReleaseClient $releases;
    private $verify;
    private $notify;

    public function __construct(RuntimeState $state, ReleaseClient $releases, callable $verify, callable $notify)
    {
        $this->state = $state;
        $this->releases = $releases;
        $this->verify = $verify;
        $this->notify = $notify;
    }

    public function run(array $settings): void
    {
        $lock = $this->state->lock('monitor');
        try {
            if (($settings['Authentication'] ?? 'password') === 'jwt') {
                $status = TokenStatus::inspect($settings['JWT_Token'] ?? '');
                if (!in_array($status['level'], ['expired', 'invalid'], true)) {
                    try {
                        ($this->verify)($settings);
                    } catch (RestAPIException $e) {
                        if (in_array($e->getCode(), [401, 403], true)) {
                            $status['level'] = 'rejected';
                            $status['message'] = 'Gateway SRS rejected the configured JWT. Replace it or correct the API user permissions; registrar automation cannot authenticate.';
                        } else {
                            $status['verification_error'] = true;
                        }
                    } catch (\Throwable $e) {
                        $status['verification_error'] = true;
                    }
                }
                $status['checked_at'] = time();
                $environment = in_array($settings['AccountMode'] ?? '', ['ote', 'dev'], true) ? $settings['AccountMode'] : 'live';
                $this->state->write('token-' . $environment, $status);
                if ($status['level'] !== 'healthy') {
                    $sent = $this->state->read('notifications-' . $environment);
                    if (($sent['fingerprint'] ?? '') !== $status['fingerprint']) {
                        $sent = ['fingerprint' => $status['fingerprint'], 'levels' => []];
                    }
                    if (empty($sent['levels'][$status['level']]) && ($this->notify)($status, $environment)) {
                        $sent['levels'][$status['level']] = time();
                        $this->state->write('notifications-' . $environment, $sent);
                    }
                }
            }
            $this->checkRelease();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function checkRelease(bool $force = false): array
    {
        $cached = $this->state->read('release');
        if (!$force && time() - ($cached['attempted_at'] ?? 0) < 86400) {
            return $cached;
        }
        $lock = $this->state->lock('release-check');
        try {
            try {
                $cached = $this->releases->latest();
            } catch (\Throwable $e) {
                $cached['error'] = 'The release check failed. Try again later or open the public releases page.';
            }
            $cached['attempted_at'] = time();
            $this->state->write('release', $cached);
            return $cached;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function tokenStatus(array $settings): ?array
    {
        if (($settings['Authentication'] ?? 'password') !== 'jwt') {
            return null;
        }
        $status = TokenStatus::inspect($settings['JWT_Token'] ?? '');
        $environment = in_array($settings['AccountMode'] ?? '', ['ote', 'dev'], true) ? $settings['AccountMode'] : 'live';
        $last = $this->state->read('token-' . $environment);
        if (($last['fingerprint'] ?? '') === $status['fingerprint']) {
            $status = array_merge($status, array_intersect_key($last, array_flip(['checked_at', 'verification_error'])));
            if (($last['level'] ?? '') === 'rejected' && !in_array($status['level'], ['expired', 'invalid'], true)) {
                $status['level'] = 'rejected';
                $status['message'] = $last['message'];
            }
        }
        return $status;
    }
}
