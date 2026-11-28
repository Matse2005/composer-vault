# Composer Vault

Composer Vault is a self-hosted server that stores Composer credentials (for example repo.magento.com keys) encrypted in one place and hands them to servers and CI pipelines through a scoped, revocable token. Rotating a key becomes one change in the vault instead of a manual edit of `auth.json` on every server.

Every credential is encrypted with its own data key, and the data keys are encrypted with a master key (`VAULT_KEY`) that never touches the database. Admins manage projects, credentials and tokens in a web interface protected by a mandatory second factor. Servers and pipelines fetch the credentials of one project with a token that can be limited to IP addresses, given an expiry and revoked at any time.

```
 admin browser ----> vault (admin UI, 2FA, audit log) ----> database (ciphertext only)
                          |
                          | VAULT_KEY lives only in the server environment
                          v
 server / CI --token--> GET /api/v1/projects/<slug>/composer-auth --> COMPOSER_AUTH json
        |
        +--> composer install (uses the credentials, never stores them)
```

## Requirements

- PHP 8.5 with the sodium extension.
- A database: SQLite, MySQL 8 or PostgreSQL.
- HTTPS. The vault refuses to be considered healthy without it.
- A cron entry for the scheduler.

No queue worker and no Redis are needed.

## Install on Forge

See [docs/deploy/forge.md](docs/deploy/forge.md).

## Install on VitoDeploy

See [docs/deploy/vitodeploy.md](docs/deploy/vitodeploy.md).

## Install on a plain server

1. Clone the repository and run `composer install --no-dev --optimize-autoloader`.
2. Run `npm ci && npm run build`.
3. Copy `.env.example` to `.env` and set `APP_URL` (with `https://`) and the database settings. Only the bootstrap variables are needed; everything else is set in the dashboard after the install, see [docs/settings.md](docs/settings.md).
4. Run `php artisan vault:install`. It generates `APP_KEY` and `VAULT_KEY` when they are missing, migrates the database and creates the first admin. If the `.env` file cannot be written, it prints the key lines instead and stops: add them to the environment and run it again.
5. Point the web server at the `public/` directory.
6. Add the scheduler to cron: `* * * * * cd /path/to/vault && php artisan schedule:run >> /dev/null 2>&1`.
7. Log in, set up two-factor authentication or a passkey, and run `php artisan vault:doctor`.

On a server with no interactive terminal, run `php artisan vault:install --no-interaction --name="Your Name" --email="you@example.com"`. It never takes a password: it prints a one-time link where the admin chooses one.

## Using it in a project

Create a project, a credential and a token in the admin interface, then fetch the credentials with the token:

```bash
export COMPOSER_AUTH=$(curl -fsS -H "Authorization: Bearer $COMPOSER_VAULT_TOKEN" \
  https://vault.example.com/api/v1/projects/my-project/composer-auth)
composer install
```

The full interface, including the error responses, is in the [API contract](docs/api.md).

## Monitoring

`php artisan vault:doctor` runs every health check now and prints a table (`--json` for machines, `--strict` to fail on warnings too). The same checks run every five minutes through the scheduler and are shown on the Security status page in the admin interface, with a button to run them again. They cover the environment, debug mode, the database, the scheduler heartbeat, https, sodium, the vault key, key versions, the `.env` permissions, the session cookie, trusted proxies, admins without a second factor, the audit log chain and the existence of an admin. Results are never exposed over HTTP; only logged in admins see them.

To be told when a check fails, set a mail address, a Slack incoming webhook or both on the Notifications page of the dashboard (an Owner does this). `VAULT_HEALTH_NOTIFY_MAIL` and `VAULT_HEALTH_NOTIFY_SLACK_WEBHOOK` override the dashboard values and lock those fields. A failing check is reported at most once an hour. The messages hold check names and summaries, never a secret.

## Backups

Back up the database and `VAULT_KEY` separately. The database holds only ciphertext, and without `VAULT_KEY` every stored credential is lost. A backup that contains both gives an attacker everything, so keep the key in a password manager or secret store, not in the same archive as the database. [spatie/laravel-backup](https://github.com/spatie/laravel-backup) is an option for the database; never include the `.env` file in its archive.

## Upgrading

Pull the new version, run `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build` and `php artisan migrate --force`, then `php artisan vault:doctor`. The deploy scripts in the guides do exactly this.

## Verifying a release

Every release archive has a SHA-256 checksum file and a build provenance attestation. After downloading `composer-vault-<version>.tar.gz` and its `.sha256` file from the release page, check both:

```bash
sha256sum --check composer-vault-<version>.tar.gz.sha256
gh attestation verify composer-vault-<version>.tar.gz --repo Matse2005/composer-vault
```

## Key rotation

Run `php artisan vault:rotate-key`. It re-encrypts the data key of every credential with a new master key in one transaction and prints the new environment values: set `VAULT_KEY` and `VAULT_KEY_VERSION` to the new values and add the old key to `VAULT_PREVIOUS_KEYS` as `<version>:<key>`. Keep the old entry until `vault:doctor` reports that no credential uses it any more, then remove it.

## Running behind a proxy or load balancer

The vault trusts no proxy by default, so it sees the address of the proxy as the client address and the token IP allowlists would not work. Set `TRUSTED_PROXIES` to the comma separated IP addresses or CIDR ranges of your proxies. A wildcard is refused on purpose.

## Settings

After the install, the settings are managed in the dashboard by admins whose role holds the permission for each area. A variable in the environment overrides the dashboard value and locks that field. The list, the precedence, the permissions and the recovery command are in [docs/settings.md](docs/settings.md). If a wrong setting locks you out, run `php artisan vault:settings reset --lockout` on the server.

## Company use

A company can run the vault for its own teams with roles (Owner, Admin, Auditor), sign-in through its identity provider with OpenID Connect, and tools for its security and privacy officers: retention settings, personal data export and pseudonymisation, a security event stream for a SIEM, alerts, incident tools, access reviews and a compliance report. These settings are managed in the dashboard.

- [Single sign-on](docs/compliance/sso.md)
- [Personal data](docs/compliance/gdpr.md)
- [Security controls](docs/compliance/nis2.md)

The vault supports your own compliance work. It does not make a company compliant, and none of these documents is legal advice.

## Security

See [SECURITY.md](SECURITY.md) for how to report a problem. The protections:

- Envelope encryption with XChaCha20-Poly1305 and a master key outside the database.
- Tokens are shown once and stored only as a hash; they can be limited to IP addresses and given an expiry.
- Rate limiting and blocking of repeated failed token attempts.
- A hash-chained audit log of every admin change, which `vault:doctor` verifies.
- A mandatory second factor (authenticator app or passkey) for every admin.
- Admins join by invitation and can be deactivated; deactivated admins are logged out.
- Hardened sessions, an optional admin IP allowlist and a strict content security policy.
- No secret in logs, audit entries, health results or rendered pages.
