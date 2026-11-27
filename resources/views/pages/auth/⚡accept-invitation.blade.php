<?php

use App\Actions\Vault\AcceptInvitation;
use App\Exceptions\Vault\InvalidInvitationException;
use App\Models\Invitation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth'), Title('Accept invitation')] class extends Component {
    #[Locked]
    public string $token = '';

    #[Locked]
    public string $email = '';

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $invalid = false;

    /**
     * Mount the component. The route middleware has already refused every unusable token.
     */
    public function mount(string $token): void
    {
        $invitation = Invitation::query()->where('token_hash', hash('sha256', $token))->first();

        abort_unless($invitation?->isUsable(), 404);

        $this->token = $token;
        $this->email = $invitation->email;
    }

    /**
     * Create the admin from the invitation, log them in and send them to set up a second factor.
     */
    public function accept(AcceptInvitation $acceptInvitation): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        try {
            $user = $acceptInvitation->handle($this->token, $this->name, $this->password);
        } catch (InvalidInvitationException) {
            $this->reset('name', 'password', 'password_confirmation');
            $this->invalid = true;

            return;
        }

        Auth::login($user);
        session()->regenerate();

        $this->redirectRoute('security.edit');
    }
}; ?>

<div class="flex flex-col gap-6">
    @if ($invalid)
        <x-auth-header :title="__('Invitation')" :description="\App\Exceptions\Vault\InvalidInvitationException::MESSAGE" />
    @else
        <x-auth-header :title="__('Accept your invitation')" :description="__('Choose a name and a password to join as an admin.')" />

        <form wire:submit="accept" class="flex flex-col gap-6">
            <flux:input :value="$email" :label="__('Email')" type="email" readonly />

            <flux:input
                wire:model="name"
                name="name"
                :label="__('Name')"
                type="text"
                required
                autofocus
                autocomplete="name"
            />

            <flux:input
                wire:model="password"
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <flux:input
                wire:model="password_confirmation"
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="accept-invitation-button">
                    {{ __('Join') }}
                </flux:button>
            </div>
        </form>
    @endif
</div>
