# Composer Vault design

Date: 2026-11-27
Status: approved design, not yet implemented
Working name: Composer Vault

## Goal

Servers and CI pipelines get Composer credentials (for example repo.magento.com keys) from one central, self-hosted server instead of a manually placed `auth.json`. The real keys live in one place, each server holds only a scoped, revocable token, and rotating a key is one change in the vault.

Composer Vault is a free, public, open-source product. Security is its main feature, and it must be easy to install on Forge, VitoDeploy or a plain VPS.

## Scope

In scope:

- Composer credentials only: `http-basic`, `bearer`, `github-oauth`, `gitlab-token`.
- Projects, credentials, access tokens and the links between them.
- A per-project Composer plugin and a plain HTTP endpoint for CI.
- A custom admin UI.

Out of scope for version 1:

- Application secrets such as `.env` values.
- Package mirroring or proxying.
- Admin roles and permission levels.
- External KMS backends (the interface is prepared for them).

## Decisions

| Topic | Decision |
|---|---|
| Server | Laravel 12, Livewire, Flux UI free, Tailwind |
| PHP | 8.2 minimum for server and plugin |
| Client | Per-project Composer plugin, plus a curl-friendly endpoint |
| Server authentication | One token per server or pipeline, optional IP allowlist |
| Token location | Real environment variable first, then the project `.env` |
| Missing token | Plugin does nothing (notice in `-v` mode) |
| Projects | Yes, and the plugin names its project explicitly |
| Licence | MIT for server and plugin |
| Distribution | Both repositories public, plugin on Packagist |

## Architecture

There are two parts:

1. The server: a Laravel app that stores Composer credentials encrypted and serves them through one API endpoint.
2. The plugin: `composer-vault/plugin` (final vendor name to be decided), a Composer plugin that each project requires.

Project configuration in `composer.json` contains no secrets:

```json
{
  "require": { "composer-vault/plugin": "^1.0" },
  "config": { "allow-plugins": { "composer-vault/plugin": true } },
  "extra": {
    "composer-vault": {
      "url": "https://vault.example.com",
      "project": "krijmerieke"
    }
  }
}
```

The token is `COMPOSER_VAULT_TOKEN`, read from the real environment first and then from `.env` in the project root.

## Request flow

1. On `composer install`, Composer installs plugins first and activates them.
2. The plugin reads `extra.composer-vault` and the token. Without config or token it stops here and does nothing.
3. It calls `GET /api/v1/projects/{slug}/composer-auth` with `Authorization: Bearer <token>`.
4. The server checks the token (known, not revoked, not expired), the optional IP allowlist and whether the token is linked to the project.
5. The server returns the project's credentials in Composer's auth JSON format.
6. The plugin calls `$io->setAuthentication()` for each host that has no credentials yet. Nothing is written to disk.
7. Composer downloads packages as usual.

The same endpoint response is valid `COMPOSER_AUTH` JSON, so a pipeline without the plugin can use:

```bash
export COMPOSER_AUTH=$(curl -fsS -H "Authorization: Bearer $COMPOSER_VAULT_TOKEN" \
  https://vault.example.com/api/v1/projects/krijmerieke/composer-auth)
```

Known limitation: the plugin cannot help before it is in `vendor/`, so the first `composer require` of the plugin in a new project needs credentials another way. Deploys from `composer.lock` and later `composer update` runs work.

## Data model

`users`: admins (Laravel default plus 2FA columns).

`projects`

- `name`, `slug` (unique, used in API and `composer.json`), `notes`

`credentials`

- `name`
- `type`: `http-basic`, `bearer`, `github-oauth`, `gitlab-token`
- `host`
- `username` (nullable, `http-basic` only)
- `secret_ciphertext`, `data_key_ciphertext`, `key_version`
- `notes`

`credential_project` (many to many)

- Rule: per project at most one credential per host.

`access_tokens`

- `name` (for example "prod-web-01")
- `token_prefix` (first 8 characters, for display and lookup)
- `token_hash` (SHA-256 of the full token)
- `allowed_ips` (nullable list of IPs or CIDR ranges)
- `expires_at`, `revoked_at` (nullable)
- `last_used_at`, `last_used_ip`

`access_token_project` (many to many)

`access_logs`

- `access_token_id` (nullable), `project_id` (nullable), `ip`, `user_agent`
- `result`: `ok`, `unknown_token`, `revoked`, `expired`, `ip_denied`, `unknown_project`, `project_denied`
- `created_at`
- Pruned after 90 days.

`audit_logs`

- `user_id`, `action`, `subject_type`, `subject_id`, `changes` (never secret values)
- `previous_hash`, `hash` (hash chain)
- `created_at`

## Tokens

- Format: `cv_` plus 40 random characters. The prefix lets secret scanners recognise leaked tokens.
- Stored as SHA-256. With this much randomness a slow hash adds nothing and would slow down lookups.
- Lookup by prefix, then compare the hash with `hash_equals`.
- The plain token is shown once at creation, together with a ready-to-paste `.env` line.
- Regenerating keeps projects and settings and invalidates the old value at once. Revoking takes effect on the next request.

## Encryption

- A dedicated `VAULT_KEY`, independent of `APP_KEY`, so rotating Laravel's app key never touches stored secrets.
- Envelope encryption with libsodium XChaCha20-Poly1305: each secret has its own data key, and that data key is encrypted with the master key.
- The credential ID is used as associated data, so a ciphertext copied into another row fails to decrypt.
- Key rotation with `php artisan vault:rotate-key` re-encrypts only the data keys. `key_version` records which master key encrypted each row.
- The master key sits behind a `KeyProvider` interface. Version 1 reads it from `.env`. A KMS or Vault provider can be added later without a data migration.
- Losing `VAULT_KEY` loses every secret. Installation and `vault:doctor` both tell the admin to back it up separately from the database.

## Admin UI

Stack: the Laravel Livewire starter kit with Flux UI free, Fortify for login and two-factor authentication.

Screens:

- Projects: list, create, edit, with linked credentials and tokens.
- Credentials: list, create, edit, link to projects. Secrets are write-only: after saving, the UI shows type, host, username and last change date, never the secret.
- Tokens: list with status and last use, create, regenerate, revoke, and the one-time token display.
- Logs: API access log and admin audit log, filterable by project and token.
- Security status: the `vault:doctor` checks.

Components:

- `flux:modal` for confirmations (revoke, delete, regenerate) and the one-time token display.
- Plain Blade tables with Tailwind, Livewire for sorting and pagination.
- Session flash messages for feedback.
- Checkboxes for linking projects.

## Security hardening

Admin side:

- No public registration. The first admin is created by `vault:install`. Further admins are invited with an expiring link.
- Mandatory TOTP two-factor authentication with recovery codes.
- Login rate limiting.
- Secure, HTTP-only session cookies with `SameSite=Strict`.
- Strict Content Security Policy and standard security headers.
- Optional IP allowlist for the admin UI.
- Hash-chained audit log. `vault:doctor` verifies the chain.

API side:

- HTTPS only. The plugin refuses `http://` unless the host is `localhost`.
- Rate limiting: 60 requests per minute per token, stricter limits per IP on failed attempts.
- `Cache-Control: no-store` on every response.
- Bad, revoked or expired token: 401. Blocked IP: 403, only after the token is valid. Unknown project and denied project: the same 404. The access log records the real reason.

Supply chain:

- The plugin has no dependencies beyond `composer-plugin-api`.
- Releases are tagged and built from CI with GitHub artifact attestations.
- Both repositories have a `SECURITY.md` with a disclosure process.

## Installation

Target: a standard Laravel deploy on Forge, VitoDeploy or a VPS.

1. Create a site, point it at the repository and deploy.
2. Run `php artisan vault:install`: generates `APP_KEY` and `VAULT_KEY`, runs migrations, creates the first admin.
3. Log in and set up 2FA (forced).
4. Run `php artisan vault:doctor` and fix anything it reports.

Requirements: PHP 8.2 with the sodium extension, SQLite, MySQL or PostgreSQL, HTTPS. No queue worker or Redis needed.

`vault:doctor` checks:

- `APP_ENV=production` and `APP_DEBUG=false`
- HTTPS in `APP_URL` and on requests
- `VAULT_KEY` present and valid
- `.env` not readable by other users
- Audit log hash chain intact
- Admins without 2FA

## Plugin

- Package type `composer-plugin`, requires `php >=8.2` and `composer-plugin-api ^2.0`, nothing else.
- Implements `PluginInterface`. The work happens in `activate()`, which also runs when the plugin was installed earlier in the same run.
- Reads `extra.composer-vault` from the root package. Reads the token from the environment, then from `.env` with a small built-in parser (no dotenv library, to avoid clashes with the project's own version).
- Uses Composer's `HttpDownloader`, so proxy and TLS settings apply, with a 10 second timeout.
- Fetches once per process.
- Only fills hosts that have no credentials yet, so a local `auth.json` or `COMPOSER_AUTH` always wins.

Error handling:

- No config or no token: do nothing, notice in `-v` mode.
- Token present and the server returns 401, 403 or 404, or cannot be reached: stop with a clear message that names the project and the token prefix.
- Secrets never appear in output, including `-vvv`.

## Testing

Server (Pest):

- API: valid, unknown, revoked and expired tokens, IP and CIDR allowlists, identical 404 for unknown and denied projects, response format, rate limiting, `no-store` header.
- Encryption: round trip, associated data mismatch fails, key rotation, no plaintext in the database.
- Audit log: chain verification detects edits and deletions.
- Livewire tests for every screen, including that secrets are never rendered.
- `vault:install` and `vault:doctor` command tests.

Plugin (PHPUnit):

- Unit tests for the `.env` parser, config parsing, token lookup order and mapping to `setAuthentication()`.
- Existing credentials are not overwritten.
- End-to-end: `composer install` on a fixture project against a fake vault and a fake private repository that requires basic auth.

## Open points

- Final product and vendor name (`composer-vault` is a working name; check Packagist and GitHub for conflicts).
- Passkey (WebAuthn) login for admins, after version 1.
