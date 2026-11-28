# Settings

After the first install, almost every setting is managed in the dashboard, by admins whose role holds the permission for it. This document lists them, explains which value wins, and describes how to recover when a change locks people out.

## Which value wins

For every setting the vault uses, in this order:

1. The environment variable, when it is set to a non-empty value. The field is then disabled in the dashboard and shows the variable name.
2. The value saved in the dashboard.
3. The default.

This lets a company that manages configuration as code keep doing so, while everyone else changes settings in the dashboard without a deploy. A setting that is set in both places makes the health checks warn, because the dashboard value is ignored.

Secret settings (the single sign-on client secret, the SMTP password, the Slack webhook) are stored encrypted with the same envelope encryption as credentials, are never shown again after saving, and are never written to the audit log (it only says that the secret changed). Key rotation re-encrypts them.

Dashboard values are applied at runtime on top of the cached configuration, so `config:cache` stays safe, and a change applies at once on every server behind a load balancer: nothing is cached across requests.

## Managed in the dashboard

| Setting | Environment override | Permission |
|---|---|---|
| API requests per minute and token, failed attempts per minute and address | `VAULT_API_RATE_LIMIT`, `VAULT_API_FAILED_ATTEMPTS` | `settings.api` |
| Admin IP allowlist, break-glass bypass of it | `VAULT_ADMIN_ALLOWED_IPS` | `settings.security` |
| Session idle timeout (minutes) | `SESSION_LIFETIME` | `settings.security` |
| Session lifetime (hours), deactivation after inactivity (days), invitation lifetime (hours) | `VAULT_SESSION_ABSOLUTE_HOURS`, `VAULT_ADMIN_INACTIVE_DAYS`, `VAULT_INVITATION_EXPIRE_HOURS` | `settings.security` |
| Security log channel | `VAULT_SECURITY_LOG_CHANNEL` | `settings.security` |
| Token maximum lifetime, credential rotation, access review interval | none | `settings.policies` |
| Retention: access log, audit log, invitations | `VAULT_ACCESS_LOG_RETENTION_DAYS` (access log only, as a default) | `settings.retention` |
| Organisation name and contacts | none | `settings.organisation` |
| Single sign-on, including the client secret and enforcement | `VAULT_SSO_*` | `settings.sso` |
| Identity provider groups per role | `VAULT_SSO_OWNER_GROUPS`, `VAULT_SSO_ADMIN_GROUPS`, `VAULT_SSO_AUDITOR_GROUPS` (system roles only) | `roles.manage` (on the Roles page) |
| SMTP host, port, encryption, username, password, from address and name | `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | `settings.notifications` |
| Alert and health mail address, Slack webhook, alert rules and thresholds | `VAULT_HEALTH_NOTIFY_MAIL`, `VAULT_HEALTH_NOTIFY_SLACK_WEBHOOK` | `settings.notifications` |
| Change approval | none | Owner only |

Every field shows its source (environment, dashboard or default) and its default. Every change is validated, audited with the old and new value (secrets and contact details only as "changed"), and listed on the Settings history page, where an old value can be restored with the same permission and checks as a normal change.

## Stays in the environment

| Setting | Reason |
|---|---|
| `APP_KEY`, `VAULT_KEY`, `VAULT_KEY_VERSION`, `VAULT_PREVIOUS_KEYS` | Key material must never be stored in the database it protects. |
| `DB_*` | Needed before any setting can be read. |
| `APP_ENV`, `APP_DEBUG`, `APP_URL` | Bootstrap. A dashboard switch for debug mode would let a dashboard user expose stack traces. |
| `SESSION_DRIVER`, `SESSION_SECURE_COOKIE`, the cache driver | Needed before the session starts. A wrong driver logs everyone out. |
| `TRUSTED_PROXIES` | Infrastructure. A wrong value lets clients spoof their address and bypass every IP allowlist. |
| `MAIL_MAILER` (the transport type) and the log channel definitions | Choosing `sendmail` or a file path from the dashboard is a step towards running commands or writing files on the server. Only the SMTP fields are editable, and only while `MAIL_MAILER` is `smtp`. |
| The rate limits of the login and invitation routes | Kept in code. Lowering them is never a dashboard decision. |

## Roles and permissions

A role is a set of permissions from a fixed list of 22. Owner holds all of them and cannot be changed. Admin and Auditor are editable system roles, and custom roles can be made on the Roles page.

| Area | Permissions |
|---|---|
| Projects, credentials, tokens | `projects.view`, `projects.manage`, `credentials.view`, `credentials.manage`, `tokens.view`, `tokens.manage` |
| Logs | `logs.view`, `logs.export` |
| Admins | `admins.view`, `admins.manage`, `roles.manage` |
| Settings | `settings.organisation`, `settings.security`, `settings.api`, `settings.policies`, `settings.retention`, `settings.sso`, `settings.notifications` |
| Compliance | `compliance.view`, `compliance.manage`, `incidents.manage` |
| Operations | `health.run` |

Guards against escalation: nobody grants a permission they do not hold themselves, only an Owner gives or takes away the Owner role, a role in use cannot be deleted, and nobody changes their own role or the permissions of their own role. Every action checks the permission itself, not only the page.

## Guards on risky changes

- The admin IP allowlist must contain your own address, or you must confirm in a modal that you will lose access. With the break-glass bypass on, break-glass Owners can still log in from other addresses.
- A new single sign-on issuer is checked with discovery first. Turning enforcement on needs a successful "Test sign-in" by you in the last 10 minutes and a break-glass Owner with a second factor.
- Lowering the audit log retention below the oldest entry shows how many entries the next prune deletes and needs the typed word DELETE.

### Change approval

An Owner can turn on the requirement that critical changes are approved by a second Owner. The critical changes are single sign-on settings, retention, the admin IP allowlist, the break-glass bypass, approval itself, the permissions of an existing role, and giving an existing admin the Owner role. A proposed change waits for 24 hours on the Approvals page, and another Owner approves or rejects it. The proposer cannot approve their own change. With one active Owner, changes apply directly. Approval is off by default.

## Recovery

An operator with shell access can always get back in:

```
php artisan vault:settings list
php artisan vault:settings get admin.allowed_ips
php artisan vault:settings set api.rate_limit_per_minute 120
php artisan vault:settings reset admin.allowed_ips
php artisan vault:settings reset --lockout
```

`reset --lockout` opens the admin IP allowlist, turns off single sign-on enforcement and lifts the lockdown in one step. The command works without a logged in user, has no permission check and no guard, and bypasses change approval, because recovery must always work. Every change it makes is audited with the source `console` and alerts the Owners. A secret is asked for with hidden input and cannot be given as an argument or without interaction. A setting that an environment variable sets is refused with the variable name: change the variable instead.

## Checks

The health check "Settings" fails when a stored value no longer passes its rules (for example after an upgrade changed a rule) or a stored secret cannot be decrypted, and warns when a setting is set in both the environment and the dashboard. `vault:doctor --json` and the compliance report list the source of every security-relevant setting.
