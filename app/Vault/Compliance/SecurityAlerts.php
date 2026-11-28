<?php

namespace App\Vault\Compliance;

use App\Enums\AdminRole;
use App\Enums\AlertRule;
use App\Models\User;
use App\Vault\Settings\VaultSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends security alerts to the notification address and the Slack webhook of the dashboard, synchronously, at most one
 * per rule and subject per hour. A message holds the rule, the time, addresses and IDs, never a name or an email
 * address; the one exception is that the mail of a rule that concerns the Owners also goes to the other Owners. An
 * alert that cannot be delivered is logged and never fails the action that caused it.
 */
class SecurityAlerts
{
    private const THROTTLE_SECONDS = 3600;

    private const BURST_WINDOW_SECONDS = 600;

    public function __construct(private VaultSettings $settings) {}

    /**
     * Determine whether any channel is set. Without one, nothing is sent and nothing is counted.
     */
    public function hasChannel(): bool
    {
        return $this->settings->notificationMail() !== null || $this->webhook() !== null;
    }

    /**
     * Send an alert, unless one for the same rule and subject went out in the last hour.
     *
     * @param  string  $subject  what the alert is about, for the throttle: an address, an ID or a fixed word
     * @param  string  $summary  one line with addresses and IDs only
     * @param  list<User>  $alsoMail  Owners who get the mail as well
     */
    public function send(AlertRule $rule, string $subject, string $summary, array $alsoMail = []): void
    {
        if (! $this->settings->get('alerts.rule.'.$rule->value)) {
            return;
        }

        if (! $this->hasChannel() && $alsoMail === []) {
            return;
        }

        if (! Cache::add('vault:alert:'.$rule->value.':'.hash('sha256', $subject), 1, self::THROTTLE_SECONDS)) {
            return;
        }

        $time = now()->utc()->format('Y-m-d H:i:s').' UTC';
        $title = 'Composer Vault alert: '.__($rule->label());
        $body = "{$summary}\nTime: {$time}";

        try {
            $addresses = array_values(array_unique(array_filter([
                $this->settings->notificationMail(),
                ...array_map(fn (User $owner): string => $owner->email, $alsoMail),
            ])));

            foreach ($addresses as $address) {
                Notification::route('mail', $address)->notify(new SecurityAlertNotification($title, $body));
            }

            if (($webhook = $this->webhook()) !== null) {
                Http::timeout(10)->post($webhook, ['text' => "*{$title}*\n{$body}"])->throw();
            }
        } catch (Throwable $exception) {
            Log::warning('A security alert could not be delivered.', ['rule' => $rule->value, 'error' => $exception::class]);
        }
    }

    /**
     * Count a failed API attempt from an address and alert at the threshold.
     */
    public function failedAttempt(string $ip): void
    {
        $threshold = (int) $this->settings->get('alerts.failed_attempts_threshold');
        if ($this->burst('failed:'.$ip, $threshold)) {
            $this->send(AlertRule::FailedTokenBurst, $ip, "At least {$threshold} failed API attempts within 10 minutes from {$ip}.");
        }
    }

    /**
     * Count a rejected single sign-on attempt and alert at the threshold.
     */
    public function ssoRejection(): void
    {
        $ssoThreshold = (int) $this->settings->get('alerts.sso_rejections_threshold');
        if ($this->burst('sso', $ssoThreshold)) {
            $this->send(AlertRule::SsoRejectionBurst, 'sso', "At least {$ssoThreshold} single sign-on attempts were rejected within 10 minutes.");
        }
    }

    /**
     * Alert that an Owner changed. The other active Owners get the mail too.
     */
    public function ownerChange(User $subject, string $what, ?User $actor): void
    {
        $this->send(AlertRule::OwnerChange, $what.':'.$subject->getKey(), "{$what} for admin #{$subject->getKey()}.", $this->otherOwners($subject, $actor));
    }

    /**
     * The active Owners, except the given ones.
     *
     * @return list<User>
     */
    public function otherOwners(?User ...$except): array
    {
        $excluded = array_map(fn (?User $user): ?int => $user?->getKey(), $except);

        return array_values(User::query()->active()->where('role', AdminRole::Owner->value)->get()
            ->reject(fn (User $owner): bool => in_array($owner->getKey(), $excluded, true))
            ->all());
    }

    private function webhook(): ?string
    {
        $webhook = $this->settings->secret('notifications.slack_webhook');

        return $webhook === null || $webhook === '' ? null : $webhook;
    }

    /**
     * Count one event in a ten minute window and tell whether the threshold was just reached.
     */
    private function burst(string $key, int $threshold): bool
    {
        if (! $this->hasChannel()) {
            return false;
        }

        $cacheKey = 'vault:alert-count:'.hash('sha256', $key);
        Cache::add($cacheKey, 0, self::BURST_WINDOW_SECONDS);

        return Cache::increment($cacheKey) === $threshold;
    }

    /**
     * Get the mail of an alert, for the notification class.
     */
    public static function mailOf(string $title, string $body): MailMessage
    {
        return (new MailMessage)->subject($title)->line($body);
    }
}
