<?php

use App\Actions\Vault\DeleteAccessToken;
use App\Actions\Vault\RegenerateAccessToken;
use App\Actions\Vault\RevokeAccessToken;
use App\Actions\Vault\UpdateAccessToken;
use App\Livewire\Concerns\HasTokenForm;
use App\Models\AccessLog;
use App\Models\AccessToken;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Token')] class extends Component {
    use HasTokenForm;

    #[Locked]
    public string $tokenId = '';

    /** The plain token of a regeneration. It only exists here until the one-time modal is closed. */
    #[Locked]
    public ?string $plainToken = null;

    public function mount(AccessToken $accessToken): void
    {
        $this->tokenId = $accessToken->id;
        $this->name = $accessToken->name;
        $this->projectIds = $accessToken->projects()->pluck('projects.id')->all();
        $this->allowedIps = $this->allowlistText($accessToken->allowed_ips);
        $this->expiresOn = $accessToken->expires_at?->toDateString() ?? '';
    }

    #[Computed]
    public function token(): AccessToken
    {
        return AccessToken::query()->findOrFail($this->tokenId);
    }

    /**
     * @return Collection<int, AccessLog>
     */
    #[Computed]
    public function recentLogs(): Collection
    {
        return AccessLog::query()
            ->with('project:id,slug')
            ->where('access_token_id', $this->tokenId)
            ->latest('created_at')
            ->latest('id')
            ->limit(20)
            ->get();
    }

    public function save(UpdateAccessToken $updateAccessToken): void
    {
        $validated = $this->validateTokenForm($this->token->expires_at);

        $updateAccessToken->handle(
            $this->token,
            $validated['name'],
            $validated['projectIds'],
            $validated['allowedIps'],
            $validated['expiresAt'],
        );

        session()->flash('flash', __('Token saved.'));

        $this->redirectRoute('tokens.show', $this->token, navigate: true);
    }

    public function regenerate(RegenerateAccessToken $regenerateAccessToken): void
    {
        if ($this->token->isRevoked()) {
            $this->addError('token', __('A revoked token cannot be regenerated. Issue a new token instead.'));

            return;
        }

        $this->plainToken = $regenerateAccessToken->handle($this->token)->plainToken;

        unset($this->token);
        Flux::modal('one-time-secret')->show();
    }

    /**
     * Forget the regenerated plain token. The next render of the page no longer contains it.
     */
    public function closeTokenModal(): void
    {
        $this->plainToken = null;
    }

    public function revoke(RevokeAccessToken $revokeAccessToken): void
    {
        $revokeAccessToken->handle($this->token);

        unset($this->token);
        session()->flash('flash', __('Token revoked.'));

        $this->redirectRoute('tokens.show', $this->token, navigate: true);
    }

    public function delete(DeleteAccessToken $deleteAccessToken): void
    {
        $deleteAccessToken->handle($this->token);

        session()->flash('flash', __('Token deleted.'));

        $this->redirectRoute('tokens.index', navigate: true);
    }
}; ?>

<section class="w-full max-w-3xl space-y-10">
    <div class="space-y-1">
        <flux:heading size="xl" level="1">{{ $this->token->name }}</flux:heading>
        <flux:text>
            <span class="font-mono text-xs">cv_{{ $this->token->token_prefix }}...</span>
            -
            @if ($this->token->isRevoked())
                {{ __('Revoked') }}
            @elseif ($this->token->isExpired())
                {{ __('Expired') }}
            @else
                {{ __('Active') }}
            @endif
        </flux:text>
        <flux:error name="token" />
    </div>

    <form wire:submit="save" class="space-y-6">
        <flux:heading size="lg" level="2">{{ __('Settings') }}</flux:heading>

        <x-token-fields :projects="$this->projects" />

        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>

    <div class="space-y-4">
        <flux:heading size="lg" level="2">{{ __('Recent requests') }}</flux:heading>

        @if ($this->recentLogs->isEmpty())
            <flux:text>{{ __('This token has not been used yet.') }}</flux:text>
        @else
            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-start text-sm">
                    <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-2 text-start font-medium">{{ __('Time') }}</th>
                            <th class="px-4 py-2 text-start font-medium">{{ __('Result') }}</th>
                            <th class="px-4 py-2 text-start font-medium">{{ __('Project') }}</th>
                            <th class="px-4 py-2 text-start font-medium">{{ __('IP') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->recentLogs as $log)
                            <tr wire:key="log-{{ $log->id }}">
                                <td class="px-4 py-2" title="{{ $log->created_at }}">{{ $log->created_at->diffForHumans() }}</td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $log->result->value }}</td>
                                <td class="px-4 py-2">{{ $log->project?->slug ?? $log->requested_slug ?? '-' }}</td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $log->ip }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <flux:heading size="lg" level="2">{{ __('Token actions') }}</flux:heading>

        <div class="flex flex-wrap gap-2">
            @unless ($this->token->isRevoked())
                <flux:modal.trigger name="regenerate-token">
                    <flux:button>{{ __('Regenerate') }}</flux:button>
                </flux:modal.trigger>

                <flux:modal.trigger name="revoke-token">
                    <flux:button variant="danger">{{ __('Revoke') }}</flux:button>
                </flux:modal.trigger>
            @endunless

            <flux:modal.trigger name="delete-token">
                <flux:button variant="danger">{{ __('Delete') }}</flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    <flux:modal name="regenerate-token" class="min-w-[22rem] max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Regenerate this token?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('The old value stops working at once. Projects, allowed addresses and expiry stay. The new value is shown once.') }}
                </flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button wire:click="regenerate" variant="primary">{{ __('Regenerate') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="revoke-token" class="min-w-[22rem] max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Revoke this token?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('The token stops working on its next request. A revoked token cannot be used again.') }}
                </flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button wire:click="revoke" variant="danger">{{ __('Revoke') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="delete-token" class="min-w-[22rem] max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this token?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('The token is removed and stops working. Its requests stay in the access log. This cannot be undone.') }}
                </flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button wire:click="delete" variant="danger">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    @if ($plainToken)
        <x-one-time-secret
            :value="$plainToken"
            :line="'COMPOSER_VAULT_TOKEN='.$plainToken"
            :title="__('Your new token')"
        />
    @endif
</section>
