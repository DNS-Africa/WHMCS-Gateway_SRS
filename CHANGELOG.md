# Changelog

## 4.2.0 — 2026-11-06

- Synchronize domain lifecycle from EPP and redemption statuses; holds, pending transfers and deletion states no longer reactivate solely because expiry is in the future.
- Require positive sponsoring-registrar evidence before marking a domain transferred away. Poll explicit incoming transfer outcomes and report approved, rejected and cancelled transfers with current ownership checks.
- Return the WHMCS Domain information object with hold, redemption and server-lock state; throw on information errors as required by WHMCS.
- Recheck premium prices before registration, renewal and transfer. Compare exact decimal costs and configured currency, support fee and charge quotes, reject conflicting or missing quotes, and send supported EPP price agreements.
- Block premium terms beyond the API's verifiable one-year quote and charge-only premium renewals/transfers where the current API cannot enforce the agreement.
- Add client and administrator DNSSEC DS record management, modern digest validation, domain ownership/permission checks, CSRF protection, stale-form rejection, preservation of unrelated settings and read-back confirmation.
- Show account balance, credit limit, available credit, currency and freshness in Gateway SRS Manager. Add configurable low/critical available-credit alerts, administrator notices, email retries, escalation, recovery and deduplication for both authentication modes.
- Keep balance snapshots and alert history isolated by environment and credentials. Preserve stale values on API failure without inventing zero balances or recovery.
- Add manager API 2 and companion version 1.1.0. Install both module directories manually when upgrading from 4.1; future registrar updates continue to preserve the manager bootstrap.
- Expand offline regression coverage and opt-in read-only API checks. Authenticated OTE and real WHMCS acceptance remain deployment checks.

## 4.1.0 — 2026-11-06

- Add explicit portal-password and portal-issued JWT authentication, with encrypted WHMCS settings and API validation.
- Add isolated Live, OTE and Development origins; remove authentication against production in test modes.
- Replace session-wide bearer storage with an environment/account/credential-scoped process cache. Bound password re-authentication to one retry; never re-authenticate a supplied JWT with portal credentials.
- Add 30-day and urgent 7-day JWT warnings, expiry/rejection status, daily administrator emails and notification deduplication.
- Add daily GitHub stable-release checks, cached notices, release notes and an optional admin manager.
- Add signed update manifests, archive/file checksums, strict archive validation, compatibility checks, staged replacement, rollback and independent interrupted-update recovery.
- Update domain/contact routes to name/ID lookup, handle empty DELETE responses and current renewal action responses, and use the current pricing API's complete-list option.
- Preserve EPP codes during unrelated nameserver changes, support IPv6 glue and consolidate domain information reads.
- Avoid treating a missing domain or an API failure as proof of an outgoing transfer; keep unknown incoming transfers pending.
- Remove credential-bearing logs, hard-coded test/documentation credentials and hooks that logged cart data or created unattached contacts.
- Add deterministic release packaging, signing tools, restricted public staging and PHP test/build workflows.

The first installation of 4.1.0 and its public verification key is manual. The companion manager API is version 1; registrar self-updates preserve that recovery bootstrap. Authenticated automated acceptance checks are read-only and never contact production.
