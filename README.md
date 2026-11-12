# Gateway SRS registrar module for WHMCS

Gateway SRS integrates domain registration, renewal, transfer, contacts, nameservers and pricing with WHMCS, with DNSSEC self-service, lifecycle and premium-price safeguards, account balance monitoring, JWT authentication and signed admin updates. Version 5.1 checks registrations and renewals that time out before they can be repeated, and explains registry errors with the registry's own reason and a transaction reference. Version 5.0 fixed DNSSEC and Domain Lock on WHMCS 9.1, made the Contacts and Auth Code pages much faster, added **Generate new code** for auth codes and registry-404 alerts, and operates in **USD** only. See the [release notes](https://github.com/DNS-Africa/WHMCS-Gateway_SRS/releases) for the full list.

> [!WARNING]
> **Test each release in your OTE environment before installing it on a live WHMCS.** Install it on a WHMCS that uses your Gateway SRS OTE account, check domain management (DNSSEC, Domain Lock, Contacts, Auth Code), the setup page and low-funds alerts, and only then upgrade production. Confirm your Gateway SRS account reports its balance in USD before upgrading from 4.x.

## Requirements and installation

Use WHMCS 8.6 or later with PHP 8.1 or later and the curl, json and iconv extensions. Admin updates additionally require ZIP and OpenSSL. Confirm that your WHMCS version supports the selected PHP version.

Download the latest stable `gateway_srs.zip` from [GitHub Releases](https://github.com/DNS-Africa/WHMCS-Gateway_SRS/releases). Back up the existing module and extract the ZIP into the WHMCS root. It installs:

- `modules/registrars/gateway_srs/`
- `modules/addons/gateway_srs_manager/`

How to upgrade depends on the installed version:

| Installed | Upgrade to 5.1 |
| --- | --- |
| 5.0.x or 4.2.x | **Gateway SRS Manager → Install latest stable release** (registrar directory only). |
| 4.1.x | Install **both directories manually**: 5.1 needs companion manager 1.1.0 (manager API 2), which the 4.1 updater refuses. |
| 4.0.x or older | Install **both directories manually**; these versions have no updater. |

Future registrar-only updates preserve the manager's recovery bootstrap. Keep the administrator directory name configured in WHMCS; the module uses relative admin links and does not assume it is named `admin`.

Activate Gateway SRS under **Configuration → System Settings → Domain Registrars**. Activate **Gateway SRS Manager** under **Addon Modules**, granting access only to the appropriate administrator roles. The manager also requires the **Configure Domain Registrars** permission, including for update and recovery POST actions.

The registrar is listed as **Gateway SRS**. Its installed module version appears separately in registrar configuration and the manager; it is independent of the WHMCS application version. Release builds take the module version from `modules/registrars/gateway_srs/version.json` and the product name from `whmcs.json`.

## Account modes and authentication

Choose the environment explicitly. A token or account from one environment must not be reused in another.

| Mode | API origin |
| --- | --- |
| Live | `https://api.gatewaysrs.com/` |
| OTE | `https://api-ote.gatewaysrs.com/` |
| Development | `https://api-dev.gatewaysrs.com/` |

Registry and portal requests use `/api/` and `/portal/` below the selected origin. API requests do not follow redirects.

Choose either **Portal username and password** or **Portal-issued JWT**. Existing installations retain password authentication. For JWT authentication, create an API user in the corresponding portal with permissions for domain operations and billing/pricing, then paste its access token into the masked JWT field. WHMCS encrypts registrar settings at rest. Configuration validation checks the token against the selected portal and requires an active registrar identity.

The portal issues API-user JWTs with a 365-day lifetime. Local expiry decoding is used for scheduling only; the API checks authenticity, permissions and revocation. Password accounts requiring interactive 2FA should use an API-user JWT for unattended registrar operations. A rejected supplied JWT does not trigger a password login or an automatic token reroll.

Rerolling an API-user token invalidates the old token immediately. Coordinate replacement across all installations using that API user. The module does not store an administrator's portal token or attempt to issue its own replacement.

## Expiry and release monitoring

Keep the WHMCS daily cron running. It validates the configured JWT, sends system administrator notifications and checks the public stable release once per day. Recipients must have WHMCS system administrator emails enabled.

| Remaining token validity | State |
| --- | --- |
| More than 30 days | Healthy status and expiry date in the manager |
| 30 days or less | Amber admin notice and administrator email |
| 7 days or less | Persistent red admin notice and urgent administrator email |
| Expired or rejected | Critical notice explaining that registrar authentication needs attention |

Checks use thresholds, so a missed cron run still detects the current severity. Emails are deduplicated by token, environment and severity. Failed email delivery is retried; replacing a token resets its notification history. Admin notices recalculate expiry on page loads even if cron missed a run. No customer receives token notifications.

The manager shows installed/latest versions, release notes and the last successful release check. **Check now** refreshes status. Release-service failure preserves the last known release and does not interrupt registrar operations.

## Installation report

Once a day, and after you save the registrar settings, the module sends the Gateway SRS portal for the selected Account Mode a short installation report: a random installation ID, the WHMCS, PHP and module versions, the account mode and the authentication method. Gateway SRS uses it to plan support for WHMCS and PHP versions. No domain, client, contact, credential, hostname or URL data is sent. The installation ID is generated once and stored in the WHMCS configuration table.

It is sent again straight away when one of those values changes, for example after an upgrade. The report fails silently: it gives up after 10 seconds, never affects registrar operations or settings validation, and writes nothing to the Module Log unless **Debug** is on. The report is part of the module and has no setting to turn it off.

API requests also identify themselves with a `User-Agent` such as `GatewaySRS-WHMCS/5.1.0 WHMCS/8.13.1 PHP/8.3.14`. The GitHub release check is unchanged and does not send WHMCS or PHP versions.

## Account balance and low-funds alerts

The manager shows **account balance**, **credit limit**, **available credit**, account currency and the last successful check. Available credit includes the credit facility. Monitoring works with both password and JWT authentication and needs access to the portal billing balances endpoint.

The module operates in **USD**; there is no currency setting. In the registrar configuration, enable **Low-funds alerts** and choose **Low available credit** and **Critical available credit** in USD. The low threshold must be greater than the critical threshold. For example, USD 1000 low and USD 100 critical. If the account ever reports a balance in another currency, the manager shows a configuration alert instead of comparing it against USD thresholds. Thresholds are inclusive. Alerts default to disabled until these settings are chosen; balance visibility remains available.

Daily cron and **Check now** fetch balances. Administrators receive a low warning, an urgent critical warning on escalation, and a recovery email once available credit rises above the low threshold. Each severity is sent once per incident; successful recovery resets the incident. Failed email delivery is retried. Recipients need WHMCS system emails enabled. No balance or funding information is shown to clients.

Snapshots and email history are scoped to the selected environment and credentials. A failed API request preserves the last successful balance with an explicit error, without treating it as zero or sending a recovery email. Snapshots older than two days are marked out of date. A currency mismatch raises a configuration notice instead of comparing amounts in different currencies. These settings do not change the portal's separate balance-threshold notification policy.

## DNSSEC self-service

Clients can choose **DNSSEC** in their domain actions or **Manage DNSSEC** on the domain overview. Administrators can use **Manage DNSSEC records** in the client's domain tab, which opens the manager for that domain. The companion addon must be activated and its administrator role access granted.

The page lists DS records and allows adding or removing individual records, including the last DS record. Use the key tag, signing algorithm, digest type and digest supplied by the DNS hosting provider. New records support algorithms 8, 10, 13, 14, 15 and 16 with SHA-256 or SHA-384 digests; the target registry may support a smaller subset. Existing legacy DS records remain visible and removable. This manages delegation records; it does not sign the hosted DNS zone or generate DNS keys.

Incorrect DS records can make a domain unreachable. Follow the DNS provider's key rollover procedure and retain old records until it is safe to remove them. Removing the last DS record disables the chain of trust. Changes are protected by session tokens, WHMCS domain-management permissions, current domain ownership checks, record revisions and a local per-domain lock. The module preserves nameservers, glue, contacts and autorenew settings, then reads back the DS list to confirm the result. It does not rotate the EPP code (see **Auth Code** below). Unsupported operations, stale forms and unconfirmed writes are reported explicitly.

The API does not provide an atomic conditional update for external portal changes. Avoid editing the same domain simultaneously in the portal and WHMCS. After a timeout or an unconfirmed result, reload and inspect the current DS list before retrying.

## Auth Code (EPP code)

The client **Auth Code** page shows the code stored with the domain, which is current and loads quickly. If no stored code is available the module asks the registry directly. .za SLDs (co.za, net.za, org.za, web.za) do not use EPP codes.

Clients can replace the code with **Generate new code**. The module sets a new random code at the registry and returns to the Auth Code page with it; nameservers, contacts and DNSSEC are not changed. The old code stops working immediately, so a transfer already using it fails. The action accepts POST requests only and relies on WHMCS's CSRF token, domain ownership and domain-management permission checks.

The button is not part of the stock WHMCS templates. The module's `ClientAreaPageDomainEPPCode` hook sets `$gatewaySrsNewEppCode` for eligible Gateway SRS domains; add a form like this to your client theme's `clientareadomaingetepp.tpl`, below the code (WHMCS adds the CSRF token to POST forms automatically):

```smarty
{if $gatewaySrsNewEppCode}
    <form method="post" action="clientarea.php?action=domaindetails&amp;id={$gatewaySrsNewEppCode.domainid}&amp;modop=custom&amp;a=NewEPPCode"
          onsubmit="return confirm('Generate a new auth code? The current code will stop working.');">
        <button type="submit" class="btn btn-primary">Generate new code</button>
    </form>
{/if}
{if $smarty.get.eppcode eq 'new'}<div class="alert alert-success">A new auth code has been generated.</div>{/if}
{if $smarty.get.eppcode eq 'failed'}<div class="alert alert-danger">A new auth code could not be generated. Your current code is unchanged.</div>{/if}
```

## Lifecycle synchronization and premium prices

Enable the WHMCS domain synchronization cron. Sync reads EPP and redemption statuses with UTC expiry dates. Holds, pending transfers and deletion/redemption states do not reactivate solely because a future expiry is present. WHMCS's Sync interface has no separate hold/redemption flag; detailed hold and restorable state is returned through Domain Information. A missing domain or API error does not prove transfer-out: the module requires a positive change of sponsoring registrar. Incoming transfers use explicit query outcomes and current ownership before completion; rejected and cancelled requests return a failure reason, while unknown outcomes remain unchanged.

Premium costs are in **USD**, so keep USD present in WHMCS for saved premium costs; there is no premium currency setting. A quote in any other currency stops the purchase. Registration, renewal and transfer obtain a fresh quote and stop if approval is missing, premiums are disabled, costs/currencies disagree, or the response is ambiguous. Checks happen before registration contacts are created. Supported fee/charge agreements accompany the purchase so the registry can enforce the quoted price.

The current API contract only provides a verifiable one-year premium quote, so longer premium terms are blocked. Its renewal and transfer EPP builders support fee agreements, not charge agreements: **charge-only premium renewals and transfers are blocked** until the API supplies a fee quote or adds enforceable charge support. Registration supports either extension. Standard domains without pricing extensions retain normal EPP behavior. These safeguards do not automatically reprice or approve a customer's order.

## Admin updates and recovery

The **Install latest stable release** button appears for a newer compatible release with all signed assets. Installation independently retrieves fresh release metadata and verifies the publisher signature, ZIP checksum and every file checksum. It rejects unexpected paths, symlinks, duplicate names, oversized archives and runtime/manager incompatibility. Unsigned older releases remain available through the manual download link.

Downloads are staged while registrar operations continue. Replacement acquires an exclusive lock and refuses to proceed while registrar operations are active; retry when the module is idle. Requests arriving during the brief replacement return a busy error rather than using mixed files. Filesystem settings and database credentials are preserved. The previous registrar directory is retained until the next successful update attempt. **Restore previous version** restores that backup.

If PHP or the request stops during replacement, reopen **Gateway SRS Manager** and use **Recover previous module**. The companion manager remains outside the replaced registrar directory. Its own upgrades require manual installation when a release calls for a newer manager API.

The registrar's parent directory must be writable by the PHP worker for admin updates. Do not grant broad write permissions to the entire WHMCS installation; use manual installation if this host's deployment policy keeps module directories read-only.

## Private state directory

Monitoring stores token fingerprints, expiry metadata, balance snapshots, notification history and release metadata. API tokens and portal passwords are never stored in this directory. The default is a private, per-installation directory under the system temporary directory, owned by the PHP user with permissions 0700.

For durable notification history and recovery across restarts, configure a persistent directory **outside the web root** in WHMCS `configuration.php`:

```php
if (!defined('GATEWAY_SRS_STATE_DIR')) {
    define('GATEWAY_SRS_STATE_DIR', '/private/persistent/path/gateway-srs');
}
```

Keep the `defined()` guard: WHMCS maintenance tools can load the configuration more than once in a process, and an unguarded definition can stop an update with a duplicate-constant error.

Create it for the PHP worker with permissions 0700. Cron and web PHP must use the same user and directory. Hosts running multiple PHP nodes must share module files, state and reliable filesystem locking; otherwise use the manual deployment process. Preserve this directory alongside module backups. Clearing it resets notification history and removes the updater's recovery journal.

## Domain configuration and troubleshooting

Select Gateway SRS for automatic registration under **Domain Pricing**. Use **Utilities → Registrar TLD Sync** to import standard current registration, renewal and transfer costs. Premium/special product prices are excluded from ordinary cost imports. Current pricing imports retain the module's one-year term limit; review TLD-specific registration terms in WHMCS.

Debug mode logs HTTP method, status and retry metadata to **Utilities → Logs → Module Log**. Passwords, JWTs, authorization headers, request bodies, response bodies and contacts are not logged. A network failure during a purchase does not prove the purchase failed: check the domain's state before retrying registration, renewal or transfer.

[Registry API](https://api.gatewaysrs.com/api/docs/) · [Portal API](https://api.gatewaysrs.com/portal/docs/) · [Support](mailto:support@dns.business)
