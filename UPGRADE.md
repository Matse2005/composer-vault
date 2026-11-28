# Upgrading

Composer Vault follows [Semantic Versioning](https://semver.org/). Breaking changes only happen in major versions, and the steps for each major version are listed in this file.

## Upgrading within a major version

1. Back up the database, and check that `VAULT_KEY` is stored safely outside the server.
2. Pull the new version.
3. Run `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build`.
4. Run `php artisan migrate --force`.
5. Run `php artisan vault:doctor` and fix what it reports.

Read the [changelog](CHANGELOG.md) for the versions you skip.

## Upgrading to 1.1.0

Run the steps above. The migrations add roles: every admin that exists before the upgrade becomes an Owner, so nobody loses access. Review the roles on the Admins page afterwards and give people the role they need. New settings are managed in the dashboard; no new environment variable is required. The change of the health notification address to the dashboard keeps working with the existing environment variables.

## What each kind of release can contain

- A patch release fixes bugs and changes nothing that you have to configure.
- A minor release adds features. When it needs a new required environment variable, the changelog and this file say so.
- A major release can remove an environment variable, change the API contract in [docs/api.md](docs/api.md) or require a manual step.

## Version 1.0.0

This is the first release. There is nothing to upgrade from.
