<?php

use App\Actions\Vault\CompleteAccessReview;
use App\Models\AccessReview;
use App\Models\User;
use App\Vault\Compliance\AccessReviewReport;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Access review')] class extends Component {
    public string $notes = '';

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function admins(): Collection
    {
        return app(AccessReviewReport::class)->admins();
    }

    /**
     * @return list<array{token: App\Models\AccessToken, reasons: list<string>}>
     */
    #[Computed]
    public function tokens(): array
    {
        return app(AccessReviewReport::class)->tokens();
    }

    /**
     * @return Collection<int, App\Models\Credential>
     */
    #[Computed]
    public function credentials(): Collection
    {
        return app(AccessReviewReport::class)->credentialsDueForRotation();
    }

    /**
     * @return Collection<int, AccessReview>
     */
    #[Computed]
    public function reviews(): Collection
    {
        return AccessReview::query()->with('completedBy:id,name')->orderByDesc('completed_at')->get();
    }

    public function complete(CompleteAccessReview $completeAccessReview): void
    {
        $this->validate(['notes' => ['nullable', 'string', 'max:10000']]);

        $completeAccessReview->handle($this->notes, auth()->user());

        $this->reset('notes');
        unset($this->reviews);
        Flux::toast(__('Access review completed.'));
    }
}; ?>

<section class="w-full max-w-6xl space-y-10">
    <div>
        <flux:heading size="xl" level="1">{{ __('Access review') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Check who and what has access, then complete the review. Complete reviews are kept with a snapshot of the counts.') }}</flux:text>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Admins') }}</flux:heading>
        <div class="overflow-x-auto rounded-panel border border-border">
            <table class="w-full text-start text-sm">
                <thead class="bg-surface-sunken text-ink-muted">
                    <tr>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Name') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Role') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Sign-in') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Second factor') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Last login') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($this->admins as $admin)
                        <tr wire:key="admin-{{ $admin->id }}">
                            <td class="px-4 py-2">{{ $admin->name }}</td>
                            <td class="px-4 py-2">{{ __($admin->role->label()) }}@if ($admin->is_break_glass) ({{ __('break-glass') }})@endif</td>
                            <td class="px-4 py-2">{{ $admin->sso_subject !== null ? __('SSO') : __('Password') }}</td>
                            <td class="px-4 py-2">
                                @if ($admin->two_factor_confirmed_at) {{ __('Authenticator app') }} @endif
                                @if ($admin->passkeys_count > 0) {{ trans_choice(':count passkey|:count passkeys', $admin->passkeys_count) }} @endif
                                @unless ($admin->hasSecondFactor()) {{ __('None') }} @endunless
                            </td>
                            <td class="px-4 py-2">{{ $admin->last_login_at?->diffForHumans() ?? __('Never') }}</td>
                            <td class="px-4 py-2">{{ $admin->isDeactivated() ? __('Deactivated') : __('Active') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Tokens that need a look') }}</flux:heading>
        @forelse ($this->tokens as $row)
            <div wire:key="token-{{ $row['token']->id }}" class="flex flex-wrap items-center justify-between gap-2 rounded-card border border-border px-4 py-2">
                <span class="font-medium">{{ $row['token']->name }} <span class="font-mono text-xs">cv_{{ $row['token']->token_prefix }}...</span></span>
                <span class="flex flex-wrap gap-1">
                    @foreach ($row['reasons'] as $reason)
                        <flux:badge size="sm" color="amber">{{ __($reason) }}</flux:badge>
                    @endforeach
                </span>
            </div>
        @empty
            <flux:text>{{ __('No token needs a look.') }}</flux:text>
        @endforelse
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Credentials due for rotation') }}</flux:heading>
        @forelse ($this->credentials as $credential)
            <div wire:key="credential-{{ $credential->id }}" class="flex items-center justify-between rounded-card border border-border px-4 py-2">
                <span class="font-medium">{{ $credential->name }} <span class="text-xs">({{ $credential->host }})</span></span>
                <span class="text-sm">{{ __('Secret last changed :time', ['time' => $credential->secret_changed_at->diffForHumans()]) }}</span>
            </div>
        @empty
            <flux:text>{{ __('Nothing is due, or no rotation period is set on the Policies page.') }}</flux:text>
        @endforelse
    </div>

    @can('compliance.manage')
        <form wire:submit="complete" class="space-y-4 rounded-panel border border-border p-5">
            <flux:heading size="lg" level="2">{{ __('Complete the review') }}</flux:heading>
            <flux:textarea wire:model="notes" rows="4" :label="__('Notes')" :description="__('Notes are not written to the audit log. Keep names out of them when you can.')" />
            <flux:button type="submit" variant="primary">{{ __('Complete review') }}</flux:button>
        </form>
    @endcan

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Previous reviews') }}</flux:heading>
        @forelse ($this->reviews as $review)
            <div wire:key="review-{{ $review->id }}" class="rounded-card border border-border px-4 py-2 text-sm">
                <span class="font-medium" title="{{ $review->completed_at }}">{{ $review->completed_at->format('Y-m-d') }}</span>
                {{ __('by :name', ['name' => $review->completedBy?->name ?? __('a removed admin')]) }}:
                {{ collect($review->snapshot)->map(fn ($count, $key) => str_replace('_', ' ', $key).' '.$count)->implode(', ') }}
            </div>
        @empty
            <flux:text>{{ __('No review has been completed yet.') }}</flux:text>
        @endforelse
    </div>
</section>
