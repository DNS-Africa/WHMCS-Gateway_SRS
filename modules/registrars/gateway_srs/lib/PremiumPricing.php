<?php
namespace WHMCS\Module\Registrar\gateway_srs;

final class PremiumPricing
{
    /** Normalize the two EPP extensions supported by RegistryAPI. No guessed USD default. */
    public static function quotes(array $response, string $name, ?string $fallbackCurrency = null): array
    {
        $quotes = [];
        $charge = $response['charge'] ?? [];
        if ($charge) {
            if (!is_array($charge) || !isset($charge['category'], $charge['action']) || !is_array($charge['action'])) {
                throw new \RuntimeException('Gateway SRS returned an unrecognized charge quote.');
            }
            $premium = strtolower($charge['category']) !== 'standard';
            foreach ($charge['action'] as $action => $price) {
                if (!in_array($action, ['create', 'renew', 'transfer'], true)) { continue; }
                $currency = Amount::currency($charge['currency'] ?? $fallbackCurrency);
                $quotes[$action] = ['price' => Amount::text($price), 'currency' => $currency, 'premium' => $premium,
                    'agreement' => ['charge' => ['price' => Amount::text($price)]]];
            }
        }
        foreach ($response['fee_commands'] ?? [] as $entry) {
            if (!is_array($entry) || !isset($entry['command'], $entry['fees'], $entry['currency'])
                || !is_array($entry['fees']) || !$entry['fees']) {
                throw new \RuntimeException('Gateway SRS returned an unrecognized fee quote.');
            }
            if (isset($entry['name']) && strtolower($entry['name']) !== strtolower($name)) {
                throw new \RuntimeException('Gateway SRS returned a quote for another domain.');
            }
            $action = $entry['command'];
            if (!in_array($action, ['create', 'renew', 'transfer'], true)) { continue; }
            $fees = [];
            $total = 0;
            foreach ($entry['fees'] as $fee) {
                // RegistryAPI's elem_to_internal() retains XML text under #text.
                $value = is_array($fee) ? ($fee['#text'] ?? null) : $fee;
                $fees[] = Amount::text($value);
                $total += Amount::units($value);
            }
            $price = Amount::fromUnits($total);
            $currency = Amount::currency($entry['currency']);
            $premium = !in_array(strtolower($entry['class'] ?? ''), ['', 'standard'], true);
            if (isset($quotes[$action]) && ($quotes[$action]['price'] !== $price || $quotes[$action]['currency'] !== $currency)) {
                throw new \RuntimeException('Gateway SRS returned conflicting fee and charge prices.');
            }
            if (isset($quotes[$action]['fee_seen'])) { throw new \RuntimeException('Gateway SRS returned duplicate fee quotes.'); }
            $quotes[$action] = ['price' => $price, 'currency' => $currency,
                'premium' => $premium || ($quotes[$action]['premium'] ?? false), 'fee_seen' => true,
                'agreement' => array_merge($quotes[$action]['agreement'] ?? [], ['fee_commands' => ['currency' => $currency, 'fee' => $fees]])];
        }
        return $quotes;
    }

    public static function fetch(DNSAPI $api, string $name): array
    {
        $response = $api->domain_check($name);
        if (!is_array($response['results'] ?? null) || count($response['results']) !== 1
            || ($response['results'][0]['name'] ?? '') !== $name || !isset($response['results'][0]['avail'])) {
            throw new \RuntimeException('Gateway SRS returned no valid domain check result; purchase stopped.');
        }
        $currency = null;
        if (!empty($response['charge']) && empty($response['charge']['currency'])) {
            $currency = Amount::currency($api->balances()['currency'] ?? null);
        }
        return [$response, self::quotes($response, $name, $currency)];
    }

    public static function agreement(DNSAPI $api, array $params, string $name, string $action): array
    {
        [$response, $quotes] = self::fetch($api, $name);
        if ($action === 'create' && (string) $response['results'][0]['avail'] !== '1') {
            throw new \RuntimeException('This domain is unavailable for registration; purchase stopped.');
        }
        $accepted = array_key_exists('premiumCost', $params) && $params['premiumCost'] !== null && $params['premiumCost'] !== '';
        $quote = $quotes[$action] ?? null;
        if (!$quote) {
            if ($accepted || array_filter($quotes, static fn ($q) => $q['premium'])) {
                throw new \RuntimeException('The current premium price is unavailable for this operation; purchase stopped.');
            }
            return []; // Registries without fee extensions retain standard EPP behavior.
        }
        $agreement = $quote['agreement'];
        // RegistryAPI's renewal/transfer EPP builders currently support fee-0.9 only.
        if ($action !== 'create') { unset($agreement['charge']); }
        if (!$quote['premium'] && !$accepted) {
            return (int) ($params['regperiod'] ?? 0) === 1 ? $agreement : [];
        }
        if (empty($params['premiumEnabled']) || !$accepted) {
            throw new \RuntimeException('This domain requires an approved premium price. Enable premium domains and refresh the WHMCS quote.');
        }
        // The published RegistryAPI fee-check template quotes one year only.
        if ((int) ($params['regperiod'] ?? 0) !== 1) {
            throw new \RuntimeException('Premium operations require a one-year term because the API quotes one year.');
        }
        $currency = strtoupper(trim($params['Premium_Currency'] ?? ''));
        if ($currency === '' || Amount::currency($currency) !== $quote['currency']) {
            throw new \RuntimeException('The configured premium cost currency does not match the current registry quote; purchase stopped.');
        }
        if (Amount::units($params['premiumCost']) !== Amount::units($quote['price'])) {
            throw new \RuntimeException('The premium cost has changed. Refresh and approve the WHMCS price before retrying.');
        }
        if (!$agreement) {
            throw new \RuntimeException('The API cannot enforce a charge-only premium price for renewal or transfer. A fee quote is required; purchase stopped.');
        }
        return $agreement;
    }
}
