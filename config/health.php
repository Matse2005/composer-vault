<?php

use Spatie\Health\Models\HealthCheckResultHistoryItem;
use Spatie\Health\Notifications\CheckFailedNotification;
use Spatie\Health\Notifications\Notifiable;
use Spatie\Health\ResultStores\EloquentHealthResultStore;

return [

    /*
    |--------------------------------------------------------------------------
    | Result Stores
    |--------------------------------------------------------------------------
    |
    | The results are kept in the application database, so the vault needs no
    | extra infrastructure. They are shown on the security status page and
    | removed again after the number of days below by the daily model:prune.
    |
    */

    'result_stores' => [
        EloquentHealthResultStore::class => [
            'connection' => null,
            'model' => HealthCheckResultHistoryItem::class,
            'keep_history_for_days' => 5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | The channels and recipients are set from the "health" keys of
    | config/vault.php by the application service provider: mail and Slack are
    | only used when their address or webhook is configured. The notification
    | is not queued, so no queue worker is needed.
    |
    */

    'notifications' => [
        'enabled' => true,

        'notifications' => [
            CheckFailedNotification::class => [],
        ],

        'notifiable' => Notifiable::class,

        'throttle_notifications_for_minutes' => 60,

        'throttle_notifications_key' => 'health:latestNotificationSentAt:',

        'only_on_failure' => true,

        'mail' => [
            'to' => '',

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Composer Vault'),
            ],
        ],

        'slack' => [
            'webhook_url' => '',
            'channel' => null,
            'username' => null,
            'icon' => null,
        ],

        'teams' => [
            'webhook_url' => '',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Unused package features
    |--------------------------------------------------------------------------
    |
    | The package can expose its results over HTTP. The vault never does: the
    | results describe the security posture of the server and are only shown
    | to logged in admins. Keep the Oh Dear endpoint off.
    |
    */

    'oh_dear_endpoint' => [
        'enabled' => false,
        'always_send_fresh_results' => true,
        'secret' => null,
        'url' => '/oh-dear-health-check-results',
    ],

    'horizon' => [
        'heartbeat_url' => null,
    ],

    'schedule' => [
        'heartbeat_url' => null,
    ],

    'theme' => 'light',

    'silence_health_queue_job' => true,

    'json_results_failure_status' => 200,

    'secret_token' => null,

];
