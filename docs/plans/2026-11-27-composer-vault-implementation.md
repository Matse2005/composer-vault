# Composer Vault implementation plan

Date: 2026-11-27
Design: [2026-11-27-composer-vault-design.md](2026-11-27-composer-vault-design.md)

## Approach

Two public repositories, both MIT, built in parallel tracks that meet at an end-to-end test:

- `composer-vault-server`: Laravel 12, Livewire, Flux UI free, Pest.
- `composer-vault-plugin`: Composer plugin, PHPUnit, no runtime dependencies beyond `composer-plugin-api`.

Every task is test first: write the failing test, make it pass, commit. A task is done when its tests pass, static analysis is clean and the commit is in. Security-relevant tasks are marked with (S) and get a second review before merge.

The API contract in task S6 is the meeting point. The plugin track can start against a fake server as soon as that contract is written down, without waiting for the rest of the server.

## Track 0: repository setup

### 0.1 Server repository

- Create the repository from the Laravel Livewire starter kit (Flux UI free, Fortify).
- Set PHP to `^8.2` in `composer.json`, require `ext-sodium`.
- Add Pest, Larastan (level 8), Laravel Pint.
- GitHub Actions: tests on PHP 8.2, 8.3, 8.4 against SQLite, MySQL and PostgreSQL; Pint and Larastan as separate jobs.
- Add `LICENSE` (MIT), `SECURITY.md`, `README.md` stub, `CONTRIBUTING.md`.
- Remove public registration routes from the starter kit.

Done when: CI is green on an empty app and `/register` returns 404.

### 0.2 Plugin repository

- `composer.json`: type `composer-plugin`, `php: ^8.2`, `composer-plugin-api: ^2.0`, `extra.class` pointing at the plugin class. Dev: `composer/composer`, PHPUnit, PHPStan (level max).
- GitHub Actions: tests on PHP 8.2 to 8.4 with Composer 2.2 (LTS) and latest 2.x.
- `LICENSE` (MIT), `SECURITY.md`, `README.md` stub.

Done when: CI is green with an empty plugin class that activates.

## Track S: server

### S1 Data model

- Migrations and models: `projects`, `credentials`, `credential_project`, `access_tokens`, `access_token_project`, `access_logs`, `audit_logs`.
- Unique index on `projects.slug` and `access_tokens.token_prefix`.
- Validation rule: per project at most one credential per host (enforced in a domain service, tested at that level, because a pivot unique index cannot see `host`).
- Factories for every model.

Tests: relations, slug uniqueness, the one-credential-per-host rule when linking from either side.

### S2 Encryption (S)

- `KeyProvider` interface with `current(): MasterKey` and `forVersion(int): MasterKey`.
- `EnvKeyProvider`: reads `VAULT_KEY` (and `VAULT_PREVIOUS_KEYS` for rotation) from config. Keys are base64, 32 bytes.
- `SecretCipher` service: per-secret random data key, secret encrypted with XChaCha20-Poly1305 using the data key, data key encrypted with the master key, credential ID as associated data on both layers. Memory wiped with `sodium_memzero` where practical.
- `Credential` model: `setSecret()` and `revealSecret()` methods only, no attribute cast, so a secret never ends up in `toArray()`, JSON or logs. `$hidden` covers the ciphertext columns.

Tests: round trip; wrong credential ID fails; tampered ciphertext fails; ciphertext copied to another row fails; database contains no plaintext; `toArray()` and `toJson()` contain no secret material.

Note: the credential ID is needed as associated data, so the row is inserted first and the secret is written in the same transaction.

### S3 Key rotation (S)

- `php artisan vault:rotate-key`: generates a new key and prints it once, or takes one with `--key`. Re-encrypts all data keys inside a transaction and bumps `key_version`. Refuses to run if any row cannot be decrypted with the known keys.
- Instructions printed afterwards: put the new key in `VAULT_KEY`, move the old one to `VAULT_PREVIOUS_KEYS` until the next deploy, then remove it.

Tests: rotation keeps every secret readable; rows are rewritten; rotation aborts cleanly on an undecryptable row.

### S4 Access tokens (S)

- `TokenIssuer`: creates `cv_` plus 40 random characters (base62 from `random_bytes`), stores prefix and SHA-256 hash, returns the plain token once.
- `regenerate()` keeps projects, IP list and expiry. `revoke()` sets `revoked_at`.
- `TokenAuthenticator`: lookup by prefix, `hash_equals` on the hash, checks revoked and expired, records `last_used_at` and `last_used_ip`.
- IP allowlist matching for IPv4, IPv6 and CIDR using `Symfony\Component\HttpFoundation\IpUtils`.
- Trusted proxies configured through `TRUSTED_PROXIES`, documented, so the IP check sees the real client behind a load balancer.

Tests: issue and authenticate; wrong token; revoked; expired; regenerate invalidates the old value; IPv4, IPv6 and CIDR matching; empty allowlist allows all.

### S5 Access log

- `AccessLogger` writes one row per API request with the result codes from the design.
- Scheduled daily prune of rows older than 90 days (`model:prune`).

Tests: each result code is logged; pruning removes only old rows.

### S6 API endpoint (S)

Contract, written to `docs/api.md` first so the plugin track can start:

- `GET /api/v1/projects/{slug}/composer-auth`
- Header `Authorization: Bearer cv_...`
- 200: Composer auth JSON, only the keys that have entries: `http-basic`, `bearer`, `github-oauth`, `gitlab-token`.
- 401 for missing, unknown, revoked or expired tokens. 403 for a blocked IP with a valid token. 404 for unknown and denied projects alike. 429 when rate limited.
- Error body: `{"error": "<code>"}` with no further detail.
- Headers on every response: `Cache-Control: no-store`, `Pragma: no-cache`.

Implementation: route with a dedicated middleware stack (token auth, IP check, rate limiters), controller that loads project credentials, decrypts them and maps them to the Composer format.

Rate limits: 60 per minute per token; 10 failed attempts per minute per IP.

Tests: every status code; 404 body and timing path identical for unknown and denied project (same code path, no early return on slug lookup); response validates as Composer auth JSON; headers present; rate limits trigger; access log row per request.

### S7 Audit log (S)

- `AuditLogger` records create, update, delete, link, unlink, regenerate and revoke for projects, credentials, tokens and admins. `changes` never contains secrets or token hashes; a changed secret is logged as `"secret": "changed"`.
- Hash chain: `hash = sha256(previous_hash . canonical_json(entry))`, written inside a transaction with a lock on the last row, so concurrent writes cannot fork the chain.
- `AuditChainVerifier` returns the first broken entry or null.

Tests: entries for each action; no secret material in any entry; verifier passes on a clean chain and fails on edit, delete and reorder.

### S8 Admin authentication (S)

- Fortify with two-factor authentication. Middleware that sends any admin without confirmed 2FA to the setup page and blocks everything else.
- Invitations: `invitations` table with hashed token and 48 hour expiry, invite form, accept page that sets password and then forces 2FA setup.
- Login rate limiting (Fortify default, checked).
- Session config: `secure`, `http_only`, `same_site=strict`, short idle lifetime (60 minutes, configurable).
- Optional `ADMIN_ALLOWED_IPS` middleware for all admin routes.

Tests: no access without 2FA; invitation accept and expiry; reused invitation fails; IP allowlist blocks admin routes but not the API.

### S9 Security headers

- Middleware that sets Content Security Policy (nonces for Livewire and Alpine scripts), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `Permissions-Policy`, and `Strict-Transport-Security` when on HTTPS.

Tests: headers on admin pages; the admin UI still works under the CSP (checked in a browser test, see S11).

### S10 Admin UI screens

Built with Flux UI free, plain Blade tables, `flux:modal` for confirmations and the one-time token display, flash messages for feedback.

- Layout and navigation: Projects, Credentials, Tokens, Logs, Security status, Admins.
- Projects: list, create, edit, delete (confirm modal), linked credentials and tokens.
- Credentials: list, create, edit. Secret field is write-only and empty on edit; leaving it empty keeps the current secret. Link to projects with checkboxes, with a clear error on a host conflict.
- Tokens: list with status and last use, create (projects, IPs, expiry), one-time display modal with copy button and `.env` line, regenerate and revoke (confirm modals).
- Logs: access log and audit log, filters by project, token and result, pagination.
- Admins: list, invite, remove (cannot remove yourself or the last admin).

Tests (Livewire): every create, edit and delete path; validation errors; secret never present in rendered HTML or Livewire payload (assert on the full response and on component state); plain token appears only in the creation response.

### S11 Browser smoke test

- One Pest browser test (or Dusk) that logs in with 2FA, creates a project, credential and token, and fetches the API with that token. Runs with the CSP active.

### S12 Install and doctor commands

- `php artisan vault:install`: refuses to run when an admin exists; generates `APP_KEY` and `VAULT_KEY` if missing and writes them to `.env`; runs migrations; asks for the first admin; prints a warning to back up `VAULT_KEY` separately.
- `php artisan vault:doctor`: checks from the design, exit code 1 on any failure, `--json` output for monitoring.
- Security status page shows the same checks.

Tests: install on a fresh database; refusal when already installed; each doctor check passes and fails as expected.

### S13 Install documentation

- `README.md`: requirements, install on Forge, install on VitoDeploy, install on a plain server, backups (database and `VAULT_KEY` separately), upgrading, key rotation, trusted proxies.
- Recommended deploy script for Forge and VitoDeploy including `php artisan migrate --force` and `vault:doctor`.

Done when: a fresh install on a VitoDeploy test server, following only the README, passes `vault:doctor`.

## Track P: plugin

### P1 Configuration and token lookup

- `Config` value object from `extra.composer-vault`: `url` and `project` required together; neither means the plugin is off; one without the other is a configuration error.
- URL must be `https://`, except `localhost`, `127.0.0.1` and `[::1]`.
- `TokenResolver`: `getenv('COMPOSER_VAULT_TOKEN')`, then `$_SERVER` and `$_ENV`, then `.env` in the directory of the root `composer.json`.
- `DotenvReader`: reads only the `COMPOSER_VAULT_TOKEN` line; handles quotes, `export ` prefix, comments and CRLF; no variable expansion.

Tests: every lookup order case; malformed `.env`; quoted values; missing file; http URL refused; partial config is an error.

### P2 Fetch and inject

- `VaultClient` using Composer's `HttpDownloader` with a 10 second timeout and the bearer header. Maps 401, 403, 404, 429 and network errors to clear exceptions naming the project and token prefix.
- Response validation: only the four known auth types, correct shapes, otherwise an error.
- `AuthInjector`: for each host, `$io->hasAuthentication($host)` first; only fill hosts without credentials. `http-basic` maps to username and password, `bearer` to the token with password `bearer`, `github-oauth` to token with `x-oauth-basic`, `gitlab-token` to token with `private-token`, matching Composer's own handling.
- Fetch once per process (static guard).

Tests: each status code; invalid JSON; existing credentials not overwritten; each auth type sets what Composer expects; no secret in any output at every verbosity.

### P3 Plugin wiring

- `Plugin::activate()` runs config, token, fetch and inject. Off without config or token, with a `-v` notice. Errors stop the run with the message from P2.
- `deactivate()` and `uninstall()` are no-ops.

Tests: activation with a mocked `Composer` and `IOInterface` for each path.

### P4 End-to-end test

- Fixture project with the plugin as a path repository and a `composer.lock`.
- A PHP built-in server script that acts as a fake vault and a fake private Composer repository requiring basic auth.
- Run `composer install` in a temp directory: succeeds with the token, fails with a clear message with a wrong token, and with no token behaves as plain Composer (fails at the private repository with Composer's own 401).
- Also run against the real server from track S in CI once S6 is done (docker compose job), as the meeting point of both tracks.

## Track R: release

### R1 Security review

- Review of all (S) tasks against the design, with focus on secret exposure (logs, exceptions, Livewire state, debug pages), timing differences on the API, and the audit chain.
- Run `composer audit` and a dependency review in CI.

### R2 Release pipeline

- Tag-triggered GitHub Actions for both repositories, with GitHub artifact attestations for the release archives.
- Plugin registered on Packagist with the GitHub webhook.
- Changelog and semantic versioning from 1.0.0.

### R3 Launch documentation

- Plugin README: install, `composer.json` config, token in environment or `.env`, CI usage with curl, troubleshooting.
- Server README finished (S13), screenshots of the admin UI.

## Order and parallel work

| Step | Server | Plugin |
|---|---|---|
| 1 | 0.1, S1 | 0.2 |
| 2 | S2, S3 | P1 |
| 3 | S4, S5, S6 (contract first) | P2, P3 against the contract |
| 4 | S7, S8, S9 | P4 with fake server |
| 5 | S10, S11 | P4 against the real server |
| 6 | S12, S13 | |
| 7 | R1, R2, R3 | R1, R2, R3 |

## Open points before starting

- Final product and vendor name, checked against Packagist and GitHub.
- GitHub organisation or personal account for the two repositories.
- Passkey login stays after version 1.
