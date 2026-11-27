# Security review for 1.0.0

Scope: the code on the phase 7 branch, reviewed by a separate reviewer (an agent that did not write the code) following the checklist of ticket 7.1. The review was done by reading the code and the route list. This is not a penetration test.

## Not performed

These checklist items need a running server or a browser and were not done. They must be done by a person before the release:

- Response timing measurements (100 requests each) for unknown against denied project and unknown against wrong token. The code was read instead: both pairs return the same body and status, and an unknown prefix runs a dummy hash comparison.
- Browser checks of the content security policy on every page and of the cookie attributes. The smoke test in CI covers the login, two-factor, dashboard, project, credential and token pages only.
- `vault:rotate-key` on a realistic copy with 100 credentials. The rotation is covered by tests with a few credentials.
- Tinker output of `Credential::first()` and `AccessToken::first()`. The code was read: the secret columns are hidden.
- Triggering an exception in `ComposerAuthBuilder` and `CreateCredential` with debug on and off. The code was read: exceptions carry no secret and the API renderer returns no message or trace.

## Findings

Status values: fixed (in this phase), open (needs a decision by the owner before the release), accepted (a decision is recorded, the owner confirms).

| No | Severity | Finding | Status |
|---|---|---|---|
| 1 | medium | A passkey counted as a second factor, but a password login only challenges for an authenticator app. An admin with only a passkey could log in with the password alone. | fixed: the password login is refused for such an admin, who signs in with the passkey |
| 2 | medium | "Remember me" kept a session without a second factor for about five years. | fixed: the option is removed and a remember request is ignored |
| 3 | medium | The audit chain uses plain SHA-256. Someone with write access to the database can recompute every hash and the head row, or reset an emptied log to the start, without detection. | open: an HMAC with a key derived from VAULT_KEY would stop this, but key rotation would then need to re-hash the chain. Sending the head to somewhere outside the database would make truncation visible. |
| 4 | medium | `vault:rotate-key` commits before it prints the new key once. If the output is lost, the credentials are wrapped with a key that is stored nowhere. The `--key` option exposes the key in the shell history. | open: print the key and ask for a typed confirmation before the commit |
| 5 | low | The credential secret and the invitation passwords can stay in the Livewire snapshot after a live update or a failed validation. The snapshot is signed, not encrypted, and only travels between the server and the admin's own browser. | open |
| 6 | low | A deactivated admin could log in with a passkey. | fixed: passkey logins are authorized only for active admins |
| 7 | low | Other sessions survive a password change. | open: add session invalidation on password change |
| 8 | low | With `TRUSTED_PROXIES` empty behind a load balancer, all clients share the proxy address, so ten bad tokens a minute from anyone block every client. | accepted: documented in the README; the owner confirms |
| 9 | low | Profile, password and failed login events are not audited, and an email change needs no password confirmation. | open |
| 10 | info | One-time secrets (token, invitation link, recovery codes) are in the Livewire snapshot and the page while they are shown. | accepted: this is the one-time display; nothing is stored |
| 11 | info | `CSP_REPORT_ONLY` turned the policy into a report without any warning. `style-src` allows inline styles because Flux needs them. | fixed for the first: a health check fails in production when the policy is not enforced. The inline styles are accepted. |
| 12 | info | The local filesystem disk served files through a route. The Boost development route has no CSRF protection. | fixed for the first: serving is off. The second is only present with development dependencies, which the deploy scripts do not install. |
| 13 | info | `vault:install` does not lock or restrict the mode of the `.env` file it writes, and the non-interactive mode can create another invitation before the first is accepted. | accepted |
| 14 | info | The API answers a malformed bearer slightly faster than a well-formed unknown one, with the same body. A valid token from a disallowed address gets a 403. | accepted |
| 15 | info | All admins have equal power. | accepted: by design for 1.0.0 |

## Checks that passed

- `revealSecret()` is called in one place, `ComposerAuthBuilder`. Plain tokens only exist in the issuing code and the one-time pages. The secret columns are hidden on the models, and audit entries mask secrets and strip ciphertext. Health results and notifications carry only check names and summaries.
- The cipher is XChaCha20-Poly1305 with a data key per secret, a random nonce, and the credential id and key version bound as associated data.
- Under `/api` there is one route, the v1 endpoint. Unknown and denied projects give the same 404, and every invalid token gives the same 401. The failed-attempt limiter cannot be bypassed with `X-Forwarded-For` when no proxy is trusted.
- Every admin page and Livewire update sits behind authentication and the second factor. There is no registration route. Invitations with unknown, malformed, expired, revoked or used tokens all get the same 404, and accepting one is transactional.
- The only raw SQL uses bound parameters. There are no open redirects, no unescaped Blade output except the QR code from Fortify, and no weak randomness.

## Release condition

Findings 3, 4, 5, 7 and 9 are open, and the items under "Not performed" are not done. The plan requires every finding to be fixed or accepted by the owner before 1.0.0 is released.
