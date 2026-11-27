# API contract

This document is the contract between the Composer Vault server and its clients: the Composer plugin and plain `curl` calls from pipelines.

## Endpoint

`GET /api/v1/projects/{slug}/composer-auth`

Request headers:

- `Authorization: Bearer cv_<40 characters>` (required)
- `Accept: application/json` (recommended)

## Success: 200

The body is a JSON object in Composer's auth format. Only keys with at least one entry are present. A project without credentials returns `{}`.

```json
{
  "http-basic": {
    "repo.magento.com": { "username": "public-key", "password": "private-key" }
  },
  "bearer": {
    "packages.example.com": "token"
  },
  "github-oauth": {
    "github.com": "token"
  },
  "gitlab-token": {
    "gitlab.com": "token"
  }
}
```

The body is valid as the value of the `COMPOSER_AUTH` environment variable:

```bash
export COMPOSER_AUTH=$(curl -fsS -H "Authorization: Bearer $COMPOSER_VAULT_TOKEN" \
  https://vault.example.com/api/v1/projects/my-project/composer-auth)
```

## Errors

The body is always `{"error":"<code>"}` with nothing else.

| Status | `error` | Cause |
|---|---|---|
| 401 | `unauthenticated` | Missing, malformed, unknown, revoked or expired token |
| 403 | `forbidden` | Valid token, request IP not in the token's allowlist |
| 404 | `not_found` | Project does not exist, or the token is not linked to it |
| 429 | `too_many_requests` | Rate limit hit; the `Retry-After` header is set |

## Headers on every response

- `Cache-Control: no-store`
- `Pragma: no-cache`
- `Content-Type: application/json`

## Versioning

Breaking changes get a new prefix (`/api/v2`). Fields are only added, never removed, within `v1`.
