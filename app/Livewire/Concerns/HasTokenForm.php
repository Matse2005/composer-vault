<?php

namespace App\Livewire\Concerns;

use App\Vault\Settings\VaultSettings;
use App\Vault\Tokens\IpAllowlist;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The form of an access token, shared by the create and the show page.
 */
trait HasTokenForm
{
    public string $name = '';

    /** One IP address or CIDR range per line. */
    public string $allowedIps = '';

    /** The expiry day as Y-m-d, or an empty string for no expiry. The token expires at the end of that day. */
    public string $expiresOn = '';

    /**
     * Validate the form.
     *
     * @param  CarbonImmutable|null  $currentExpiry  The expiry the token has now. An unchanged expiry date is accepted
     *                                               even when it is in the past, and keeps its exact time.
     * @return array{name: string, allowedIps: array<int, string>|null, expiresAt: CarbonImmutable|null}
     */
    protected function validateTokenForm(?CarbonImmutable $currentExpiry = null): array
    {
        $unchangedExpiry = $currentExpiry !== null && $this->expiresOn === $currentExpiry->toDateString();

        $maxDays = app(VaultSettings::class)->tokenMaxLifetimeDays();
        $expiryRules = ['nullable', 'date_format:Y-m-d', $unchangedExpiry ? 'nullable' : 'after_or_equal:today'];

        if ($maxDays !== null && ! $unchangedExpiry) {
            $expiryRules = ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addDays($maxDays)->toDateString()];
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'allowedIps' => ['nullable', 'string', 'max:5000', $this->allowlistRule()],
            'expiresOn' => $expiryRules,
        ], [
            'expiresOn.required' => __('A token needs an expiry date within :days days.', ['days' => $maxDays]),
            'expiresOn.before_or_equal' => __('A token needs an expiry date within :days days.', ['days' => $maxDays]),
            'expiresOn.after_or_equal' => __('The expiry date must be today or later.'),
            'expiresOn.date_format' => __('Enter a valid date.'),
        ]);

        return [
            'name' => $validated['name'],
            'allowedIps' => $this->parsedAllowlist() ?: null,
            'expiresAt' => match (true) {
                $this->expiresOn === '' => null,
                $unchangedExpiry => $currentExpiry,
                default => CarbonImmutable::parse($this->expiresOn)->endOfDay(),
            },
        ];
    }

    /**
     * The allowlist lines without blanks, each trimmed.
     *
     * @return array<int, string>
     */
    protected function parsedAllowlist(): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", str_replace("\r", '', $this->allowedIps))),
            fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * A rule that names the line of the first entry that is not an IP address or CIDR range.
     */
    private function allowlistRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            foreach (explode("\n", str_replace("\r", '', (string) $value)) as $index => $line) {
                $entry = trim($line);

                if ($entry !== '' && ! IpAllowlist::isValidEntry($entry)) {
                    $fail(__('Line :line is not an IP address or CIDR range: :entry', ['line' => $index + 1, 'entry' => $entry]));

                    return;
                }
            }
        };
    }

    /**
     * Turn the stored allowlist into the text of the form.
     *
     * @param  array<int, string>|null  $allowedIps
     */
    protected function allowlistText(?array $allowedIps): string
    {
        return implode("\n", $allowedIps ?? []);
    }
}
