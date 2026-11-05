# Gateway SRS registrar module for WHMCS

Gateway SRS integrates domain registration, renewal, transfer, contacts, nameservers and pricing with WHMCS. Version 4.1 adds portal-issued JWT authentication, expiry monitoring, release notifications and signed admin updates.

## Requirements and installation

Use WHMCS 8.6 or later with PHP 8.1 or later and the curl, json and iconv extensions. Admin updates additionally require ZIP and OpenSSL. Confirm that your WHMCS version supports the selected PHP version.

Download the latest stable `gateway_srs.zip` from [GitHub Releases](https://github.com/DNS-Africa/WHMCS-Gateway_SRS/releases). Back up the existing module and extract the ZIP into the WHMCS root. It installs:

- `modules/registrars/gateway_srs/`
- `modules/addons/gateway_srs_manager/`

The first installation of this updater must be manual. Keep the administrator directory name configured in WHMCS; the module uses relative admin links and does not assume it is named `admin`.

Activate Gateway SRS under **Configuration → System Settings → Domain Registrars**. Activate **Gateway SRS Manager** under **Addon Modules**, granting access only to the appropriate administrator roles. The manager also requires the **Configure Domain Registrars** permission, including for update and recovery POST actions.

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

## Admin updates and recovery

The **Install latest stable release** button appears for a newer compatible release with all signed assets. Installation independently retrieves fresh release metadata and verifies the publisher signature, ZIP checksum and every file checksum. It rejects unexpected paths, symlinks, duplicate names, oversized archives and runtime/manager incompatibility. Unsigned older releases remain available through the manual download link.

Downloads are staged while registrar operations continue. Replacement acquires an exclusive lock and refuses to proceed while registrar operations are active; retry when the module is idle. Requests arriving during the brief replacement return a busy error rather than using mixed files. Filesystem settings and database credentials are preserved. The previous registrar directory is retained until the next successful update attempt. **Restore previous version** restores that backup.

If PHP or the request stops during replacement, reopen **Gateway SRS Manager** and use **Recover previous module**. The companion manager remains outside the replaced registrar directory. Its own upgrades require manual installation when a release calls for a newer manager API.

The registrar's parent directory must be writable by the PHP worker for admin updates. Do not grant broad write permissions to the entire WHMCS installation; use manual installation if this host's deployment policy keeps module directories read-only.

## Private state directory

Monitoring stores only token fingerprints, expiry metadata, notification history and release metadata. API tokens and portal passwords are never stored in this directory. The default is a private, per-installation directory under the system temporary directory, owned by the PHP user with permissions 0700.

For durable notification history and recovery across restarts, configure a persistent directory **outside the web root** in WHMCS `configuration.php`:

```php
define('GATEWAY_SRS_STATE_DIR', '/private/persistent/path/gateway-srs');
```

Create it for the PHP worker with permissions 0700. Cron and web PHP must use the same user and directory. Hosts running multiple PHP nodes must share module files, state and reliable filesystem locking; otherwise use the manual deployment process. Preserve this directory alongside module backups. Clearing it resets notification history and removes the updater's recovery journal.

## Domain configuration and troubleshooting

Select Gateway SRS for automatic registration under **Domain Pricing**. Use **Utilities → Registrar TLD Sync** to import standard current registration, renewal and transfer costs. Premium/special product prices are excluded from ordinary cost imports. Current pricing imports retain the module's one-year term limit; review TLD-specific registration terms in WHMCS.

Debug mode logs HTTP method, status and retry metadata to **Utilities → Logs → Module Log**. Passwords, JWTs, authorization headers, request bodies, response bodies and contacts are not logged. A network failure during a purchase does not prove the purchase failed: check the domain's state before retrying registration, renewal or transfer.

[Registry API](https://api.gatewaysrs.com/api/docs/) · [Portal API](https://api.gatewaysrs.com/portal/docs/) · [Support](mailto:support@dns.business)
