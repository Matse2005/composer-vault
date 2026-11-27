<?php

namespace App\Vault\Invitations;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * The mail that invites someone to become an admin. It is sent on demand to the invited address.
 */
class AdminInvitation extends Notification
{
    public function __construct(#[SensitiveParameter] private string $url, private int $expireHours) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('You are invited as an admin of :app', ['app' => config('app.name')]))
            ->line(__('You have been invited to become an admin of :app.', ['app' => config('app.name')]))
            ->action(__('Accept the invitation'), $this->url)
            ->line(__('This link is valid for :hours hours and can be used once.', ['hours' => $this->expireHours]))
            ->line(__('If you did not expect this invitation, you can ignore this message.'));
    }
}
