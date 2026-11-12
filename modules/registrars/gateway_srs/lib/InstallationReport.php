<?php
namespace WHMCS\Module\Registrar\gateway_srs;

/**
 * Daily installation report: tells the Gateway SRS portal which WHMCS, PHP and module versions this installation
 * runs, so DNS Africa can see the spread of versions across registrars. It sends a random installation ID, the
 * platform and module versions, the account mode and the authentication method. No domains, clients, contacts,
 * credentials, hostnames or URLs are sent.
 *
 * The report is sent at most once per INTERVAL, and immediately when any reported value changes (an upgrade, or a
 * new account mode). Callers must treat it as best-effort: see Module::reportInstallation().
 */
final class InstallationReport
{
    /** tblconfiguration key: the ID must survive temp-directory cleanup and be shared by web and cron PHP. */
    public const SETTING = 'GatewaySrsInstallationId';
    /** A little under a day, so a drifting daily cron still reports every day. */
    public const INTERVAL = 20 * 3600;
    /** After a failure, settings saves do not retry until this has passed; the daily cron always gets a turn. */
    public const RETRY_AFTER = 3600;
    private const STATE = 'installation-report';

    private RuntimeState $state;
    /** @var callable(array $settings, array $payload): void */
    private $send;
    /** @var callable(): string */
    private $platformVersion;
    /** @var callable(?string $new): ?string Loads the stored ID, or saves $new. */
    private $installationId;

    public function __construct(RuntimeState $state, callable $send, callable $platformVersion, callable $installationId)
    {
        $this->state = $state;
        $this->send = $send;
        $this->platformVersion = $platformVersion;
        $this->installationId = $installationId;
    }

    /** Version strings travel in a JSON body and a header: keep them short and free of control characters. */
    public static function cleanVersion($version): string
    {
        return substr(preg_replace('/[^A-Za-z0-9.+_-]/', '', (string) $version), 0, 32);
    }

    public function payload(array $settings): array
    {
        return [
            'installation_id' => $this->installationId(),
            'platform' => 'whmcs',
            'platform_version' => self::cleanVersion(($this->platformVersion)()),
            'php_version' => self::cleanVersion(PHP_VERSION),
            'module' => 'gateway_srs',
            'module_version' => self::cleanVersion(Module::version()['version']),
            'account_mode' => RegistryWatch::environment($settings),
            'authentication' => ($settings['Authentication'] ?? 'password') === 'jwt' ? 'jwt' : 'password',
        ];
    }

    /** @return string sent, skipped or failed. */
    public function run(array $settings, ?int $now = null): string
    {
        $now ??= time();
        $lock = $this->state->lock(self::STATE);
        try {
            $last = $this->state->read(self::STATE);
            $payload = $this->payload($settings);
            $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $due = ($last['fingerprint'] ?? '') !== $fingerprint || $now - (int) ($last['sent_at'] ?? 0) >= self::INTERVAL;
            $backingOff = $now - (int) ($last['failed_at'] ?? 0) < self::RETRY_AFTER;
            if (!$due || $backingOff) {
                return 'skipped';
            }
            try {
                ($this->send)($settings, $payload);
            } catch (\Throwable $e) {
                $this->state->write(self::STATE, ['failed_at' => $now] + $last);
                if (!empty($settings['debug'])) {
                    SafeLog::write('Installation report failed', ['event' => 'installation_report_error', 'status' => (int) $e->getCode()]);
                }
                return 'failed';
            }
            $this->state->write(self::STATE, ['fingerprint' => $fingerprint, 'sent_at' => $now]);
            return 'sent';
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function installationId(): string
    {
        $id = ($this->installationId)(null);
        if (is_string($id) && self::isUuid($id)) {
            return $id;
        }
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $id = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
        ($this->installationId)($id);
        return $id;
    }

    public static function isUuid(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id);
    }
}
