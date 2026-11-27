<?php

namespace App\Vault\Health;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Spatie\Health\Notifications\CheckFailedNotification;

/**
 * Posts a failing check notification to a Slack incoming webhook. The package's own Slack channel needs a separate
 * package, so this small channel uses the HTTP client that Laravel already has. It sends the label and the summary of
 * each check, never more.
 */
class SlackWebhookChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        $webhook = $notifiable->routeNotificationFor('slack', $notification);

        if (! is_string($webhook) || $webhook === '' || ! $notification instanceof CheckFailedNotification) {
            return;
        }

        $lines = array_map(
            fn ($result): string => '*'.$result->check->getLabel().'*: '.$result->getShortSummary(),
            $notification->results,
        );

        Http::post($webhook, [
            'text' => trans('health::notifications.check_failed_slack_message', $notification->transParameters())."\n".implode("\n", $lines),
        ])->throw();
    }
}
