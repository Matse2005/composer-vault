# Changelog

All notable changes to Composer Vault are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

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

[Unreleased]: https://github.com/Matse2005/composer-vault/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Matse2005/composer-vault/releases/tag/v1.0.0
