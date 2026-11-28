# Single sign-on

This document explains how sign-in through your identity provider works in Composer Vault. It is not legal advice.

## How it works

Composer Vault speaks OpenID Connect, with the authorization code flow, PKCE (S256), `state` and `nonce`. It uses `laravel/socialite` with the `jeffersongoncalves/laravel-oidc` driver, which reads the provider's discovery document (`/.well-known/openid-configuration`), checks the ID token signature against the provider's keys, and checks the issuer, audience, expiry, issue time and nonce. The issuer and the key set must be served over https.

The sign-in then follows these steps, and any failure ends on the same "Sign-in failed" page and in one audit entry with a reason code (never a token or a claim):

1. The sign-in must have been started less than ten minutes ago, and the state must match. A state works once.
2. The ID token must be valid, as described above.
3. The token must hold an email address and `email_verified` must be true. If allowed email domains are set, the domain must match one of them exactly (no suffix match).
4. The groups claim is mapped to a role. The highest role wins. When any group is mapped and none matches, the sign-in is refused. When no group is mapped, existing admins keep their role and nobody is provisioned.
5. The admin is found by issuer and subject. If there is none, an active admin with the same email address and no linked identity yet is linked (this is safe because step 3 needs a verified email from the configured issuer). Otherwise, an open invitation for the address creates the admin with the role of the invitation. Otherwise, when provisioning is on and a group gave a role, the admin is created. Otherwise the sign-in is refused.
6. A deactivated admin is refused by every method.
7. A changed role at the provider is followed on the next sign-in. The last active Owner is never demoted this way.

The vault stores only the issuer and the subject of the identity. The ID token, the access token, the refresh token and the claims are not stored, logged, audited or kept in the session. The client secret is stored encrypted when it is entered in the dashboard.

## Settings

Everything is set on the Single sign-on page of the dashboard (Owner only). A variable in the environment overrides the dashboard value and locks that field. The redirect URI to register at the provider is shown on the page, and is `https://<your vault>/auth/sso/callback`.

| Dashboard field | Environment override | Default |
|---|---|---|
| Single sign-on is on | `VAULT_SSO_ENABLED` | off |
| Issuer URL | `VAULT_SSO_ISSUER` | none |
| Client ID | `VAULT_SSO_CLIENT_ID` | none |
| Client secret (write-only) | `VAULT_SSO_CLIENT_SECRET` | none |
| Scopes | `VAULT_SSO_SCOPES` | `openid email profile` |
| Button label | `VAULT_SSO_BUTTON_LABEL` | `Sign in with SSO` |
| Groups claim | `VAULT_SSO_GROUPS_CLAIM` | `groups` |
| Owner, Admin and Auditor groups | `VAULT_SSO_OWNER_GROUPS`, `VAULT_SSO_ADMIN_GROUPS`, `VAULT_SSO_AUDITOR_GROUPS` | none |
| Allowed email domains | `VAULT_SSO_ALLOWED_DOMAINS` | any |
| Create an admin on the first sign-in | `VAULT_SSO_AUTO_PROVISION` | off |
| Trust the multi-factor sign-in of the provider | `VAULT_SSO_TRUST_IDP_MFA` | off |
| acr values that count as multi-factor | `VAULT_SSO_MFA_ACR_VALUES` | none |
| Enforce single sign-on | `VAULT_SSO_ENFORCE` | off |

Group lists are comma separated names exactly as the provider puts them in the token.

## Requirements on the identity provider

- It must publish a discovery document with an `issuer` that equals the issuer you configure, and with an authorization, token, userinfo and key set endpoint. The driver needs the userinfo endpoint.
- It must put `email` and `email_verified: true` in the ID token. A provider that does not send `email_verified` by default needs it configured, or nobody can sign in.
- To map roles, it must put the groups in the ID token under the claim you configure.
- The key set is cached for six hours. After a key rotation at the provider, sign-ins can fail until the cache expires.

Setup steps for specific providers (Microsoft Entra ID, Google Workspace, Okta, Keycloak) are not part of this document. They were not verified against the current documentation of each provider, and a description of their screens from memory would be wrong sooner or later. Use the provider's own documentation for registering an OpenID Connect client with the redirect URI above.

## Second factor

By default the vault's own rule applies unchanged: an admin with an authenticator app completes its challenge after the provider sign-in, and an admin with no second factor is sent to the security page to set one up. An admin with only a passkey is refused after a provider sign-in, because nothing can verify the passkey in that session; that admin signs in with the passkey directly.

Providers differ in how they report multi-factor sign-in. When the Owner turns on "Trust the multi-factor sign-in of the provider", a sign-in whose `amr` claim contains `mfa`, or whose `acr` claim is one of the configured values, counts as having used a second factor, and the local challenge is skipped. Whether to trust that is the Owner's decision.

## Enforcement and break-glass

With enforcement on, the password login, the passkey login and the password reset stop for everyone except break-glass accounts. An invitation cannot be accepted with a password; the person signs in with single sign-on and the admin is created with the role of the invitation. The invitation that `vault:install` creates for the first Owner is the one exception.

A break-glass account is an Owner marked as such on the Admins page. At most two exist. It keeps the password and the local second factor, so the company can get in when the identity provider is down. Every local break-glass login is audited and alerted to the other Owners. The health checks warn when single sign-on is enforced and there is no break-glass account, or more than two.

Before turning enforcement on, make sure at least one break-glass Owner has a working password and second factor. If you lose access, the console command `vault:settings` of a later release is meant to reset enforcement; until then, set `VAULT_SSO_ENFORCE=false` in the environment, which overrides the dashboard value.

## Leavers

The vault cannot hear about a leaver from the provider. Three limits reduce the time a leaver keeps access: a role check at every sign-in, an absolute session lifetime (12 hours by default, on the Access policy page), and the automatic deactivation of admins who have not logged in for 90 days. Deactivating the person at the provider is still the first step.
