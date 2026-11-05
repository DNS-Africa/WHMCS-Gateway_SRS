# Changelog

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
