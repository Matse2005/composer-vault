<?php

use App\Actions\Vault\IssueAccessToken;
use App\Livewire\Concerns\HasTokenForm;
use App\Models\Project;
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

    #[Locked]
    public string $projectId = '';

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
    }

    public function project(): Project
    {
        return Project::query()->findOrFail($this->projectId);
    }

    public function save(IssueAccessToken $issueAccessToken): void
    {
        $validated = $this->validateTokenForm();

        $issued = $issueAccessToken->handle(
            $this->project(),
            $validated['name'],
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

        $this->redirectRoute('tokens.show', [$this->projectId, $tokenId], navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <div class="space-y-1">
        <flux:text><flux:link :href="route('projects.show', $this->project())" wire:navigate>{{ $this->project()->name }}</flux:link></flux:text>
        <flux:heading size="xl" level="1">{{ __('New server token') }}</flux:heading>
        <flux:text>{{ __('The token can fetch the credentials of :project only. A server that deploys two projects needs two tokens.', ['project' => $this->project()->name]) }}</flux:text>
    </div>

    <form wire:submit="save" class="space-y-6">
        <x-token-fields />

        <div class="flex gap-2">
            <flux:button type="submit" variant="primary">{{ __('Create token') }}</flux:button>
            <flux:button variant="ghost" :href="route('projects.show', $this->project())" wire:navigate>{{ __('Cancel') }}</flux:button>
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
