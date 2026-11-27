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

];
