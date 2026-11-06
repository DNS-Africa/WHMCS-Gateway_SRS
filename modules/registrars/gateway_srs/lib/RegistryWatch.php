<?php
namespace WHMCS\Module\Registrar\gateway_srs;

/**
 * Tracks Gateway SRS domains the registry API answers with HTTP 404 ("no domain record is available to this
 * account"). Administrators get one digest email per newly missing set and an admin-area notice; clients get a
 * clear message instead of a domain page that silently cannot be managed.
 *
 * Entries are kept per account environment (live/OTE/dev), because switching Account Mode changes which
 * domains the API can see. Callers must treat every method as best-effort: see Module::registryMissing().
 */
final class RegistryWatch
{
    public const DIGEST_LIMIT = 50;
    private const CLOSED_STATUSES = ['Cancelled', 'Transferred Away', 'Fraud'];

    private RuntimeState $state;
    /** @var callable(int[]): array<int, array{domain: string, userid: int, status: string, registrar: string}> */
    private $lookup;

    public function __construct(RuntimeState $state, callable $lookup)
    {
        $this->state = $state;
        $this->lookup = $lookup;
    }

    public static function environment(array $settings): string
    {
        $mode = $settings['AccountMode'] ?? 'live';
        return in_array($mode, ['ote', 'dev'], true) ? $mode : 'live';
    }

    public static function label(string $environment): string
    {
        return ['live' => 'production', 'ote' => 'OTE', 'dev' => 'development'][$environment] ?? $environment;
    }

    private static function key(array $settings): string
    {
        return 'registry-missing-' . self::environment($settings);
    }

    /** Record a registry 404. Writes only when the domain is new (or its WHMCS ID was unknown), not on every page view. */
    public function missing(array $settings, string $domain, int $domainId = 0): void
    {
        $name = strtolower(trim($domain));
        if ($name === '') { return; }
        $known = $this->state->read(self::key($settings))[$name] ?? null;
        if ($known && ($known['domain_id'] || !$domainId)) { return; }
        $this->update($settings, static function (array $entries) use ($name, $domainId): array {
            $entry = $entries[$name] ?? ['domain' => $name, 'first_seen' => time(), 'domain_id' => 0, 'notified_at' => 0];
            $entry['domain_id'] = $entry['domain_id'] ?: $domainId;
            $entries[$name] = $entry;
            return $entries;
        });
    }

    /** The registry returned the domain again: clear it so a later 404 is a new incident. */
    public function found(array $settings, string $domain): void
    {
        $name = strtolower(trim($domain));
        if (!isset($this->state->read(self::key($settings))[$name])) { return; }
        $this->update($settings, static function (array $entries) use ($name): array {
            unset($entries[$name]);
            return $entries;
        });
    }

    public function isMissing(array $settings, string $domain): ?array
    {
        return $this->state->read(self::key($settings))[strtolower(trim($domain))] ?? null;
    }

    /** Current missing domains that still matter: assigned to Gateway SRS and not closed in WHMCS. Oldest first. */
    public function entries(array $settings): array
    {
        return $this->relevant($this->state->read(self::key($settings)));
    }

    /**
     * Send one digest of missing domains not yet reported. They are marked notified only when $send returns true,
     * so a failed email is retried by the next run. Entries for deleted, reassigned or closed domains are pruned.
     *
     * @param callable(array $newEntries, string $environment, int $totalMissing): bool $send
     * @return int Number of domains reported.
     */
    public function notify(array $settings, callable $send): int
    {
        $reported = 0;
        $this->update($settings, function (array $entries) use ($settings, $send, &$reported): array {
            $relevant = $this->relevant($entries);
            $kept = [];
            foreach ($relevant as $entry) { $kept[$entry['domain']] = array_intersect_key($entry, array_flip(['domain', 'first_seen', 'domain_id', 'notified_at'])); }
            $pending = array_values(array_filter($relevant, static fn (array $entry) => empty($entry['notified_at'])));
            if ($pending && $send($pending, self::environment($settings), count($relevant))) {
                foreach ($pending as $entry) { $kept[$entry['domain']]['notified_at'] = time(); }
                $reported = count($pending);
            }
            return $kept;
        });
        return $reported;
    }

    private function relevant(array $entries): array
    {
        $ids = array_values(array_filter(array_map(static fn ($entry) => (int) ($entry['domain_id'] ?? 0), $entries)));
        $rows = $ids ? ($this->lookup)($ids) : [];
        $result = [];
        foreach ($entries as $name => $entry) {
            if (!is_array($entry)) { continue; }
            $entry += ['domain' => (string) $name, 'first_seen' => 0, 'domain_id' => 0, 'notified_at' => 0];
            if ($entry['domain_id']) {
                $row = $rows[$entry['domain_id']] ?? null;
                // Deleted, reassigned to another registrar, renamed, or closed in WHMCS: no longer an incident.
                if (!$row || ($row['registrar'] ?? '') !== 'gateway_srs' || strtolower($row['domain'] ?? '') !== $entry['domain']
                    || in_array($row['status'] ?? '', self::CLOSED_STATUSES, true)) { continue; }
                $entry['userid'] = (int) ($row['userid'] ?? 0);
                $entry['status'] = (string) ($row['status'] ?? '');
            }
            $result[] = $entry;
        }
        usort($result, static fn (array $a, array $b) => [$a['first_seen'], $a['domain']] <=> [$b['first_seen'], $b['domain']]);
        return $result;
    }

    private function update(array $settings, callable $change): void
    {
        $key = self::key($settings);
        $lock = $this->state->lock($key);
        try {
            $this->state->write($key, $change($this->state->read($key)));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
