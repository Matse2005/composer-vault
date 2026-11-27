# Composer Vault

Composer Vault is a self-hosted server that stores Composer credentials (for example repo.magento.com keys) encrypted in one place and hands them to servers and CI pipelines through a scoped, revocable token. Rotating a key becomes one change in the vault instead of a manual edit of `auth.json` on every server.

Status: in development

The design and the build plans are in [docs/plans/](docs/plans/).
