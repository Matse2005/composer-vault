# Personal data

This document describes the personal data that Composer Vault processes and the tools it has for it. The vault supports your own assessment. It does not make your company compliant, and this document is not legal advice.

## Roles

The company that runs a Composer Vault installation decides why and how the data in it is processed. In the terms of data protection law that makes the company the controller. The authors of Composer Vault have no access to your installation and process none of its data.

## What the vault processes

The Data processing page in the dashboard (all roles can read it) shows the same inventory with the retention configured now, as input for your own record of processing activities.

| Category | Data | Where | Kept |
|---|---|---|---|
| Admin accounts | Name, email address, role, second factor type, passkey names, last login time | Users table | Until an Owner pseudonymises the account. Admins are deactivated, never deleted. |
| Sessions | IP address, user agent, last activity | Sessions table | Until the session ends, and expired rows are deleted daily |
| Audit log | Who did what and when (by account number), the IP address of the request | Audit log table | 365 days by default |
| API access log | IP address of the caller, token prefix, project, result | Access log table | 90 days by default |
| Invitations | Email address of the invited person and the admin who invited them | Invitations table | 30 days after the invitation expired, was accepted or was revoked |
| Recent projects | Which projects an admin opened most recently (account number, project, time), for the sidebar | User recent projects table | Until the project is deleted. Not cleared when an admin is pseudonymised: it holds only the account number. |

The audit log and the access log hold no names and no email addresses. People are referenced by account number. A test seeds known names and addresses and scans both tables to keep it that way.

API callers are servers and pipelines, but their IP addresses can still be personal data when a person runs a pipeline from a home connection.

## Retention

Retention is set on the Retention page (Owner only): access log, audit log and invitations. A nightly task deletes what is older. The minimums (30 days for the access log, 90 days for the audit log, 1 day for invitations) are product guards against losing evidence by accident, not legal requirements. The right periods are your decision.

When audit entries are deleted, the vault verifies the hash chain over them first, stores a checkpoint with the last deleted entry, and records the deletion in the audit log. The chain stays verifiable from the checkpoint. If the chain is broken, nothing is deleted.

## Access and erasure requests

Export: an admin can download their own personal data from the profile page, and an Owner can export the data of any admin. The file is JSON and holds the profile, role, second factor type, passkey names and dates, last login, sessions and the audit entries the person caused. It never holds a password hash, a two-factor secret, recovery codes, passkey credentials or session contents. Each export is audited.

Erasure: an Owner can pseudonymise a deactivated admin. The name becomes `Former admin <code>`, the email becomes `deleted-<id>@invalid`, and the password, second factor, passkeys, sessions and single sign-on link are removed. The account stays so that audit entries keep pointing to it, now under the pseudonym. A pseudonymised admin cannot be reactivated.

What stays, and why: IP addresses in audit entries are part of the hash chain, which would break if they were changed. They leave the vault through the audit log retention. Whether that satisfies a particular erasure request is for your data protection officer to decide.

## What this document does not cover

Your lawful basis, your records of processing, your processor agreements (for example with your hosting provider), international transfers and the contents of the notes you type into incident and review records. Notes are free text and are not written to the audit log, but they are stored, so keep names out of them when you can.
