<?php

use App\Actions\Vault\IssueAccessToken;
use App\Livewire\Concerns\HasTokenForm;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New token')] class extends Component {
    use HasTokenForm;

    /** The plain token. It only exists here until the one-time modal is closed. */
    #[Locked]
    public ?string $plainToken = null;

    #[Locked]
    public ?string $tokenId = null;

    public function save(IssueAccessToken $issueAccessToken): void
    {
        $validated = $this->validateTokenForm();

        $issued = $issueAccessToken->handle(
            $validated['name'],
            $validated['projectIds'],
            $validated['allowedIps'],
            $validated['expiresAt'],
            auth()->user(),
        );

        $this->plainToken = $issued->plainToken;
        $this->tokenId = $issued->token->id;

        Flux::modal('one-time-secret')->show();
    }

    /**
     * Forget the plain token and go to the token.
     */
    public function closeTokenModal(): void
    {
        $tokenId = $this->tokenId;

        $this->plainToken = null;
        $this->tokenId = null;

        session()->flash('flash', __('Token created.'));

        $this->redirectRoute('tokens.show', $tokenId, navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('New token') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <x-token-fields :projects="$this->projects" />

        <div class="flex gap-2">
            <flux:button type="submit" variant="primary">{{ __('Create token') }}</flux:button>
            <flux:button variant="ghost" :href="route('tokens.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>

    @if ($plainToken)
        <x-one-time-secret
            :value="$plainToken"
            :line="'COMPOSER_VAULT_TOKEN='.$plainToken"
            :title="__('Your new token')"
        />
    @endif
</section>
