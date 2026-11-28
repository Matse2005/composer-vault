# Changelog

All notable changes to Composer Vault are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.1.0]

Single sign-on, roles and compliance tools.

### Added

- Roles: Owner, Admin and Auditor. Every action checks the role of the person, and at least one active Owner always remains.
- Sign-in through an OpenID Connect identity provider (Socialite with the laravel-oidc driver), role mapping from a groups claim, linking by email, optional provisioning, enforcement with break-glass Owners, and sign-out at the provider.
- A settings store with a dashboard: organisation details, single sign-on (the client secret is stored encrypted), access policy, retention, policies and notifications. A value set in the environment overrides the dashboard value and locks the field.
- Admin lifecycle: reactivation, an absolute session lifetime, automatic deactivation of inactive admins, and revocation of the invitations of a deactivated admin.
- Retention with a daily ault:prune, including audit log pruning with checkpoints that keep the hash chain verifiable.
- Personal data export and pseudonymisation of deactivated admins, and a Data processing page.
- A security event stream to a log channel, security alerts by mail and Slack, incident tools (emergency actions, incident records, timeline export), access reviews with a token lifetime policy, and a compliance report (ault:compliance-report and a page).
- Documents for single sign-on, personal data and security controls in `docs/compliance/`.

### Changed

- The health notification mail and Slack webhook are now dashboard settings. `VAULT_HEALTH_NOTIFY_MAIL` and `VAULT_HEALTH_NOTIFY_SLACK_WEBHOOK` still work and lock the fields.
- Login audit entries carry the method (`sso` or `local`), and every login sets the last login time.
- Admins who exist before the upgrade become Owners.

### Dependencies

- Added `laravel/socialite`, `jeffersongoncalves/laravel-oidc` and `firebase/php-jwt`.

## [1.0.0]

First release.

### Added

- Encrypted storage of Composer credentials (http-basic, bearer and others) with envelope encryption: every credential has its own data key, and the data keys are encrypted with a master key (`VAULT_KEY`) that is kept outside the database. Key rotation with `vault:rotate-key` and previous keys in `VAULT_PREVIOUS_KEYS`.
- Projects, credentials and access tokens, managed in an admin interface. Tokens are shown once, stored only as a hash, and can be limited to IP addresses, given an expiry and revoked.
- The API endpoint `GET /api/v1/projects/{slug}/composer-auth`, which returns the credentials of a project as `COMPOSER_AUTH` JSON, with rate limiting, blocking of repeated failed attempts and an access log.
- A hash-chained audit log of every admin change, with chain verification in the admin interface and in `vault:doctor`.
- Admin accounts by invitation, a mandatory second factor (authenticator app or passkey), deactivation of admins, session hardening, an optional admin IP allowlist and a strict content security policy.
- `vault:install` to prepare the keys and the database and create the first admin, and `vault:doctor` with a Security status page and failure notifications by mail or Slack.
- Installation guides for Laravel Forge and VitoDeploy.

[Unreleased]: https://github.com/Matse2005/composer-vault/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/Matse2005/composer-vault/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/Matse2005/composer-vault/releases/tag/v1.0.0
