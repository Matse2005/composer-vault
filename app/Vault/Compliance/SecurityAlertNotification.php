<?php

namespace App\Vault\Compliance;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The mail of a security alert. It is not queued: alerts are sent in the request that caused them.
 */
class SecurityAlertNotification extends Notification
{
    public function __construct(private readonly string $title, private readonly string $body) {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return SecurityAlerts::mailOf($this->title, $this->body);
    }
}
