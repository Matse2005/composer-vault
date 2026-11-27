<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vault Master Key
    |--------------------------------------------------------------------------
    |
    | The master key encrypts the data key of every stored credential. It is
    | independent of APP_KEY. Use the format "base64:" followed by the base64
    | encoding of exactly 32 random bytes. Losing this key loses every secret,
    | so back it up separately from the database.
    |
    */

    'key' => env('VAULT_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Vault Key Version
    |--------------------------------------------------------------------------
    |
    | The version number of the current master key. Key rotation raises it by
    | one and keeps the old key available through the previous keys below.
    |
    */

    'key_version' => (int) env('VAULT_KEY_VERSION', 1),

    /*
    |--------------------------------------------------------------------------
    | Previous Master Keys
    |--------------------------------------------------------------------------
    |
    | Comma separated entries of "<version>:<key>", for example
    | "1:base64:abc...,2:base64:def...". Keep an old key here until every
    | credential has been re-encrypted with the current key.
    |
    */

    'previous_keys' => env('VAULT_PREVIOUS_KEYS', ''),

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Comma separated IP addresses or CIDR ranges of the reverse proxies in
    | front of the application. Only these may set the client IP through
    | forwarding headers. A wildcard is not allowed.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    /*
    |--------------------------------------------------------------------------
    | API Limits
    |--------------------------------------------------------------------------
    |
    | The number of requests a token may make per minute, and the number of
    | failed attempts (401 and 403 responses) an IP address may make per minute
    | before it is blocked for the rest of that minute.
    |
    */

    'api' => [
        'rate_limit_per_minute' => (int) env('VAULT_API_RATE_LIMIT', 60),
        'failed_attempts_per_minute' => (int) env('VAULT_API_FAILED_ATTEMPTS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin IP Allowlist
    |--------------------------------------------------------------------------
    |
    | Comma separated IP addresses or CIDR ranges that may reach the admin
    | interface. Empty means every address may. Behind a reverse proxy,
    | TRUSTED_PROXIES has to be set as well, or every request appears to come
    | from the proxy.
    |
    */

    'admin' => [
        'allowed_ips' => env('VAULT_ADMIN_ALLOWED_IPS', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin Invitations
    |--------------------------------------------------------------------------
    |
    | The number of hours an invitation link for a new admin stays valid.
    |
    */

    'invitations' => [
        'expire_hours' => (int) env('VAULT_INVITATION_EXPIRE_HOURS', 48),
    ],

    /*
    |--------------------------------------------------------------------------
    | Access Log Retention
    |--------------------------------------------------------------------------
    |
    | The number of days that API access log rows are kept. Older rows are
    | removed by the daily model:prune task.
    |
    */

    'access_log_retention_days' => (int) env('VAULT_ACCESS_LOG_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Health Notifications
    |--------------------------------------------------------------------------
    |
    | Where a failing health check is reported. Each channel is only used when
    | it is set: an email address for mail, an incoming webhook url for Slack.
    | The messages hold the check names and summaries, never a secret.
    |
    */

    'health' => [
        'notify_mail' => env('VAULT_HEALTH_NOTIFY_MAIL'),
        'notify_slack_webhook' => env('VAULT_HEALTH_NOTIFY_SLACK_WEBHOOK'),
    ],

];
