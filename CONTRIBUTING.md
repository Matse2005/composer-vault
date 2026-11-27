# Contributing

## Setup

Requirements: PHP 8.5 with the sodium extension, Composer, Node.js and npm.

```bash
composer setup
```

This installs the dependencies, creates `.env`, generates the app key, runs the migrations and builds the frontend.

## Running the checks

```bash
composer test
```

This runs the Pint check, PHPStan and the Pest suite. All three must pass before you open a pull request. Run `vendor/bin/pint --dirty --format agent` first to fix formatting.

## Branches and pull requests

Nothing is committed directly to `main` or to a phase branch.

- A phase branch `phase/<n>-<name>` is created from `main` and merged into `main` when every exit criterion of the phase is met.
- A ticket branch `feature/<n>.<m>-<slug>` is created from its phase branch and merged into that phase branch through a pull request.
- A pull request needs green CI and is merged with a merge commit. The ticket branch is deleted afterwards.
- Write the failing tests first, then the implementation. One ticket per pull request.
- Commit subjects are short and imperative, for example "Add secret cipher".

## Security issues

Do not report security issues in public issues or pull requests. Follow [SECURITY.md](SECURITY.md).
