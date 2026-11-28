# Security controls

This document describes the security controls that Composer Vault provides and how a company can use them in its own assessment, for example under the NIS2 directive. It is not legal advice. The vault supports a company in its assessment; it does not make a company compliant, and a certification is a different thing.

The texts of the directive were not checked by the authors while writing this document and the vault, so no article numbers are cited. Map the controls below to the articles yourself.

## Compliance report

`php artisan vault:compliance-report` (with `--json` for machines) and the Compliance report page show the state of eight controls, each as in place, partly or not in place, with the evidence the vault can measure.

| Control | What the vault measures |
|---|---|
| Multi-factor authentication | The share of active admins with a second factor, or provider multi-factor when single sign-on is enforced and trusted |
| Single sign-on and central deprovisioning | Single sign-on on, enforced, and the number of break-glass accounts |
| Access control and least privilege | Active admins per role, and the last access review against its interval |
| Encryption of secrets | Credentials on the current key version |
| Logging and integrity | The audit chain verifies, and the security event stream is on |
| Incident handling | An alert channel is set, and the number of open incidents |
| Retention | The configured retention per log |
| Health | The latest health check run |

The report is input for your own assessment. It cannot know your organisation, your processes or your other systems.

## Roles

Owner, Admin and Auditor. An Owner has full control, including admins, settings and the compliance tools. An Admin manages projects, credentials and tokens and reads the logs. An Auditor reads the lists, the logs, the compliance pages and the access review, and can export logs, but changes nothing. Every action checks the role itself, not only the page. At least one active Owner always remains.

## Security event stream

For a SIEM, choose a log channel on the Access policy page (or set `VAULT_SECURITY_LOG_CHANNEL`). Every audit entry and every failed API access is then also written to it as one JSON line, after the database transaction commits. The audit lines hold `type`, `time`, `event`, `sequence`, `actor_id`, `subject_type`, `subject_id` and `ip`. The access lines hold `type`, `time`, `result`, `ip`, `token_prefix` and `project_slug`. Nothing else is written: no audit properties, no names, no user agents. A failing channel never fails a request; the health checks warn about it.

The line is the log message. The timestamp and level around it come from the formatter of the channel. Two setups, using the log drivers Laravel ships with:

For a local agent that reads syslog, add a channel to `config/logging.php` and select it:

```php
'security' => ['driver' => 'syslog', 'level' => 'info', 'facility' => LOG_AUTH],
```

For a container platform that collects what a process writes to standard error:

```php
'security' => [
    'driver' => 'monolog',
    'level' => 'info',
    'handler' => Monolog\Handler\StreamHandler::class,
    'with' => ['stream' => 'php://stderr'],
    'formatter' => Monolog\Formatter\JsonFormatter::class,
],
```

With the JSON formatter the vault's line is in the `message` field of the outer JSON object. How your collection tool parses that depends on the tool, and this document names no product.

## Alerts

Alerts go to the mail address and the Slack webhook set on the Notifications page (Owner only). The rules, with thresholds fixed in code, are: 20 or more failed API attempts from one address within 10 minutes; a token without an address allowlist used from a new address; a local break-glass login; someone becoming an Owner or an Owner being deactivated; 10 or more rejected single sign-on attempts within 10 minutes; a key rotation; an audit chain break; and a change of retention or policy settings. Each rule fires at most once an hour for the same subject. Messages hold the rule, the time, addresses and IDs, never a name or an email address in Slack. The mail of the break-glass and Owner rules also goes to the other Owners.

## Incident tools

The Incidents page (Owner only) has emergency actions, each behind a typed confirmation and each audited with the number of items affected: revoke the tokens of one project, revoke every token, sign out every other admin, and lock the admin interface down to Owners. It also keeps incident records and exports the timeline of an incident (audit entries and failed and successful API accesses between detection and close) as JSON or CSV, with the same fields as the event stream.

The record shows three reference times counted from the detection: after 24 hours, after 72 hours and after one month. These are points to check your reporting duties against. The vault did not verify the reporting time limits of the directive, so confirm them, and in particular what each limit is counted from. Whether and to whom an incident must be reported is decided by your company and the applicable law.

## Access reviews and hygiene

The Access review page lists the admins with role, sign-in method, second factor and last login; tokens that need a look (no expiry, expiring within 14 days, not used for 90 days, no address allowlist, expiry beyond the policy); and credentials that are due for rotation. An Owner completes the review, which stores the notes and a snapshot of counts. The Policies page sets a maximum token lifetime (a new token then needs an expiry within it), a credential rotation period and the review interval. A health check warns when the last review is older than the interval.

## Admin lifecycle

Admins are deactivated, never deleted. Deactivation ends sessions and revokes the invitations the admin sent. Sessions end a fixed number of hours after the login (12 by default), and admins who have not logged in for a configured number of days (90 by default) are deactivated every night, except break-glass accounts and the last Owner.
