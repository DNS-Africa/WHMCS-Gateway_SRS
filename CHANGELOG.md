# Changelog

## 5.0.0 — 2026-11-06

> [!WARNING]
> **Test this release in your OTE environment before installing it on a live WHMCS.** Install it on a WHMCS that uses the Gateway SRS OTE account, run through domain management (DNSSEC, Domain Lock, Contacts, Auth Code), the setup page and low-funds alerts, and only then upgrade production.

This is the first published release since 4.0.126; it also contains everything listed under 4.1.0, 4.2.0 and 4.2.1 below.

**How to upgrade**

| Installed version | Upgrade to 5.0.0 |
| --- | --- |
| 4.2.x | **Addons → Gateway SRS Manager → Check now → Install latest stable release** (see below). |
| 4.1.x | Install the full ZIP manually, replacing **both** module folders: 5.0.0 needs Gateway SRS Manager 1.1.0, which the 4.1 updater cannot install. |
| 4.0.x or older | Install the full ZIP manually; these versions have no updater. |

Manual installation: back up your WHMCS files and the existing module, then extract `gateway_srs.zip` into the WHMCS root. It installs `modules/registrars/gateway_srs/` (the registrar) and `modules/addons/gateway_srs_manager/` (Gateway SRS Manager).

**Gateway SRS Manager (admin addon)**

Gateway SRS Manager is the companion admin page for the registrar. It shows the installed and latest module versions, the account balance and low-funds status, and the API token's expiry, and it installs signed updates. Set it up once after a manual installation:

1. Activate the registrar under **Configuration → System Settings → Domain Registrars → Gateway SRS**.
2. Activate **Gateway SRS Manager** under **Configuration → System Settings → Addon Modules**. In its **Configure** settings, give access only to the administrator roles that manage registrars; those administrators also need the **Configure Domain Registrars** permission.
3. Open **Addons → Gateway SRS Manager**. Use **Check now** to refresh the release, balance and token status (the daily WHMCS cron also does this).
4. When a newer release is published, **Install latest stable release** appears. It downloads the release, verifies the publisher's signature and every file, and replaces only the registrar folder; the manager itself is not changed. The registrar's parent folder must be writable by the PHP user; otherwise install manually.
5. **Restore previous version** puts back the registrar files from before the last install. If an install is interrupted, reopen the manager and use **Recover previous module**.

**Fixes**

- WHMCS 9.1: opening DNSSEC from the domain sidebar no longer ends the session. WHMCS 9.1 CSRF-checks custom module links and destroys the whole PHP session (logging out administrators using *Login as Client*) when the token is missing; the module now adds the token to the sidebar link and to its own DNSSEC button.
- Domain Lock (and unlock, block/unblock, suspend/unsuspend, transfer approve/reject) failed with HTTP 500 because an empty request body was sent as a JSON list; it is now sent as an object.
- DNSSEC permission checks use WHMCS's own `checkContactPermission('managedomains', true)`, which works for owners, delegated users and *Login as Client* on WHMCS 8.12 through 9.1.

**Faster pages**

- Contacts: one domain lookup replaces seven API calls (about 1.1 s to 0.2 s on OTE); the domain view already contains each full contact.
- Auth Code: the stored code from the domain lookup replaces the live registry query (about 5.4 s to 0.2 s on OTE, same code); the live query remains a fallback when no stored code exists.

**New**

- *Generate new code* on the Auth Code page creates and sets a new auth code (POST only, CSRF-checked, not for .za SLDs). Nameservers, contacts and DNSSEC are unchanged. The button needs a small addition to the client theme's `clientareadomaingetepp.tpl`; see the operator guide.
- Registry 404 alerts: domains the API reports as missing are recorded per environment, administrators receive one digest email for newly missing domains and an admin-area notice, the admin domain tab shows the registry status, and clients see that the domain cannot be managed right now instead of a silently broken page. Entries clear when the registry finds the domain again.
- DNSSEC page redesigned to the client theme's card layout (published DS records, label-left form, help text); the admin page keeps a Bootstrap 3 layout.
- Live Playwright acceptance tests click the real sidebar DNSSEC link as a client and through *Login as Client* and assert both sessions survive; session capture now uses the same browser profile as the tests.

Compatibility: checked against WHMCS 8.12.1 and 9.1 behaviour and PHP 8.1 language features. WHMCS 8.6–8.11 were not re-verified for this release.

## 4.2.1 — 2026-11-06

- Keep the registrar name stable across WHMCS registrar lists, domain selectors and module metadata; show the installed module version separately from `version.json`.
- Keep ZIP and unpacked public distribution metadata identical, with release checks that reject versioned product names and stale staged assets.
- Explain account/environment-specific domain-detail 404 responses without treating them as authentication failures, guessing ownership or changing domain state.

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
