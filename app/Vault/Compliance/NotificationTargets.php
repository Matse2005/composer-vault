<?php

namespace App\Vault\Compliance;

use App\Vault\Health\SlackWebhookChannel;
use App\Vault\Settings\VaultSettings;
use Spatie\Health\Notifications\CheckFailedNotification;
use Throwable;

/**
 * Points the health notifications at the mail address and Slack webhook of the dashboard. A channel is only used when
 * its address or webhook is set. It runs for console commands (the scheduled health check), where the notifications
 * are sent, and reads nothing when the settings table does not exist yet.
 */
class NotificationTargets
{
    public static function applyToHealthConfig(): void
    {
        $mail = null;
        $webhook = null;

        try {
            $settings = app(VaultSettings::class);
            $mail = $settings->notificationMail();
            $webhook = $settings->secret('notifications.slack_webhook');
        } catch (Throwable) {
            // The table is missing, as on a fresh install before the migrations ran.
        }

        $channels = [];

        if (filled($mail)) {
            $channels[] = 'mail';
            config(['health.notifications.mail.to' => $mail]);
        }

        if (filled($webhook)) {
            $channels[] = SlackWebhookChannel::class;
            config(['health.notifications.slack.webhook_url' => $webhook]);
        }

        config([
            'health.notifications.notifications' => [CheckFailedNotification::class => $channels],
            'health.notifications.enabled' => $channels !== [],
        ]);
    }
}
