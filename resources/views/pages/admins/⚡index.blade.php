<?php

use App\Actions\Vault\ChangeAdminRole;
use App\Actions\Vault\DeactivateAdmin;
use App\Actions\Vault\InviteAdmin;
use App\Actions\Vault\SetBreakGlass;
use App\Actions\Vault\RevokeInvitation;
use App\Enums\AdminRole;
use App\Exceptions\Vault\BreakGlassNotAllowedException;
use App\Exceptions\Vault\CannotDeactivateAdminException;
use App\Exceptions\Vault\LastOwnerException;
use App\Exceptions\Vault\InvitationNotAllowedException;
use App\Models\Invitation;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Admins')] class extends Component {
    public string $email = '';

    public string $role = 'admin';

    /** The invitation link. It only exists here until the one-time modal is closed. */
    #[Locked]
    public ?string $invitationUrl = null;

    #[Locked]
    public bool $mailSent = false;

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function activeAdmins(): Collection
    {
        return User::query()->active()->withCount('passkeys')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function deactivatedAdmins(): Collection
    {
        return User::query()->whereNotNull('deactivated_at')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Invitation>
     */
    #[Computed]
    public function invitations(): Collection
    {
        return Invitation::query()
            ->with('inviter:id,name')
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->orderBy('expires_at')
            ->get();
    }

    public function invite(InviteAdmin $inviteAdmin): void
    {
        $validated = $this->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::enum(AdminRole::class)],
        ]);

        try {
            $issued = $inviteAdmin->handle($validated['email'], auth()->user(), AdminRole::from($validated['role']));
        } catch (InvitationNotAllowedException $exception) {
            $this->addError('email', $exception->getMessage());

            return;
        }

        $this->invitationUrl = $issued->url;
        $this->mailSent = ! in_array(config('mail.default'), ['log', 'array'], true);
        $this->reset('email');
        unset($this->invitations);

        Flux::modal('invite-admin')->close();
        Flux::modal('one-time-secret')->show();
    }

    /**
     * Forget the invitation link. The next render of the page no longer contains it.
     */
    public function closeInvitationModal(): void
    {
        $this->invitationUrl = null;
        $this->mailSent = false;
    }

    public function revokeInvitation(string $invitationId, RevokeInvitation $revokeInvitation): void
    {
        $revokeInvitation->handle(Invitation::query()->findOrFail($invitationId));

        unset($this->invitations);
        Flux::toast(__('Invitation revoked.'));
    }

    public function changeRole(int $userId, string $role, ChangeAdminRole $changeAdminRole): void
    {
        $newRole = AdminRole::tryFrom($role);

        if ($newRole === null) {
            return;
        }

        try {
            $changeAdminRole->handle(User::query()->findOrFail($userId), $newRole, auth()->user());
        } catch (LastOwnerException $exception) {
            $this->addError('deactivate', $exception->getMessage());

            return;
        }

        unset($this->activeAdmins);
        Flux::toast(__('Role changed.'));
    }

    public function setBreakGlass(int $userId, bool $on, SetBreakGlass $setBreakGlass): void
    {
        try {
            $setBreakGlass->handle(User::query()->findOrFail($userId), $on, auth()->user());
        } catch (BreakGlassNotAllowedException $exception) {
            $this->addError('deactivate', $exception->getMessage());

            return;
        }

        unset($this->activeAdmins);
        Flux::toast($on ? __('Marked as break-glass.') : __('Break-glass mark removed.'));
    }

    public function deactivate(int $userId, DeactivateAdmin $deactivateAdmin): void
    {
        try {
            $deactivateAdmin->handle(User::query()->findOrFail($userId), auth()->user());
        } catch (CannotDeactivateAdminException|LastOwnerException $exception) {
            $this->addError('deactivate', $exception->getMessage());
            Flux::modals()->close();

            return;
        }

        unset($this->activeAdmins, $this->deactivatedAdmins);
        Flux::modals()->close();
        Flux::toast(__('Admin deactivated.'));
    }
}; ?>

<section class="w-full max-w-5xl space-y-10">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Admins') }}</flux:heading>

        <flux:modal.trigger name="invite-admin">
            <flux:button variant="primary" icon="plus">{{ __('Invite admin') }}</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:error name="deactivate" />

    <div class="space-y-4">
        <flux:heading size="lg" level="2">{{ __('Active admins') }}</flux:heading>

        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-start text-sm">
                <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Name') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Email') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Role') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Second factor') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Created') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->activeAdmins as $admin)
                        <tr wire:key="admin-{{ $admin->id }}">
                            <td class="px-4 py-2">{{ $admin->name }}</td>
                            <td class="px-4 py-2">{{ $admin->email }}</td>
                            <td class="px-4 py-2">
                                <flux:select size="sm" :value="$admin->role->value" wire:change="changeRole({{ $admin->id }}, $event.target.value)" :aria-label="__('Role of :name', ['name' => $admin->name])">
                                    @foreach (\App\Enums\AdminRole::cases() as $roleOption)
                                        <flux:select.option :value="$roleOption->value">{{ __($roleOption->label()) }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                @if ($admin->is_break_glass)
                                    <flux:badge size="sm" color="amber" class="mt-1">{{ __('Break-glass') }}</flux:badge>
                                    <flux:button size="xs" variant="ghost" wire:click="setBreakGlass({{ $admin->id }}, false)">{{ __('Remove mark') }}</flux:button>
                                @elseif ($admin->role === \App\Enums\AdminRole::Owner)
                                    <flux:button size="xs" variant="ghost" wire:click="setBreakGlass({{ $admin->id }}, true)" wire:confirm="{{ __('A break-glass Owner keeps the password and the local second factor when single sign-on is enforced, so the vault stays reachable when the identity provider is down. Mark this Owner?') }}">{{ __('Make break-glass') }}</flux:button>
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                @if ($admin->two_factor_confirmed_at)
                                    <flux:badge size="sm" color="green">{{ __('Authenticator app') }}</flux:badge>
                                @endif
                                @if ($admin->passkeys_count > 0)
                                    <flux:badge size="sm" color="green">{{ trans_choice(':count passkey|:count passkeys', $admin->passkeys_count) }}</flux:badge>
                                @endif
                                @unless ($admin->hasSecondFactor())
                                    <flux:badge size="sm" color="amber">{{ __('None yet') }}</flux:badge>
                                @endunless
                            </td>
                            <td class="px-4 py-2" title="{{ $admin->created_at }}">{{ $admin->created_at?->diffForHumans() }}</td>
                            <td class="px-4 py-2 text-end">
                                @if ($admin->isNot(auth()->user()))
                                    <flux:modal.trigger :name="'deactivate-'.$admin->id">
                                        <flux:button size="sm" variant="danger">{{ __('Deactivate') }}</flux:button>
                                    </flux:modal.trigger>

                                    <flux:modal :name="'deactivate-'.$admin->id" class="min-w-[22rem] max-w-md text-start">
                                        <div class="space-y-6">
                                            <div>
                                                <flux:heading size="lg">{{ __('Deactivate :name?', ['name' => $admin->name]) }}</flux:heading>
                                                <flux:text class="mt-2">
                                                    {{ __('This admin can no longer log in and open sessions end. The account and its audit entries are kept.') }}
                                                </flux:text>
                                            </div>
                                            <div class="flex justify-end gap-2">
                                                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                                                <flux:button wire:click="deactivate({{ $admin->id }})" variant="danger">{{ __('Deactivate') }}</flux:button>
                                            </div>
                                        </div>
                                    </flux:modal>
                                @else
                                    <flux:text class="text-xs">{{ __('You') }}</flux:text>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="space-y-4">
        <flux:heading size="lg" level="2">{{ __('Pending invitations') }}</flux:heading>

        @if ($this->invitations->isEmpty())
            <flux:text>{{ __('There are no pending invitations.') }}</flux:text>
        @else
            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-start text-sm">
                    <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-2 text-start font-medium">{{ __('Email') }}</th>
                            <th class="px-4 py-2 text-start font-medium">{{ __('Role') }}</th>
                            <th class="px-4 py-2 text-start font-medium">{{ __('Invited by') }}</th>
                            <th class="px-4 py-2 text-start font-medium">{{ __('Expires') }}</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->invitations as $invitation)
                            <tr wire:key="invitation-{{ $invitation->id }}">
                                <td class="px-4 py-2">{{ $invitation->email }}</td>
                                <td class="px-4 py-2">{{ __($invitation->role->label()) }}</td>
                                <td class="px-4 py-2">{{ $invitation->inviter?->name ?? '-' }}</td>
                                <td class="px-4 py-2" title="{{ $invitation->expires_at }}">
                                    @if ($invitation->expires_at->isPast())
                                        {{ __('Expired') }}
                                    @else
                                        {{ $invitation->expires_at->diffForHumans() }}
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-end">
                                    <flux:button size="sm" wire:click="revokeInvitation('{{ $invitation->id }}')">{{ __('Revoke') }}</flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if ($this->deactivatedAdmins->isNotEmpty())
        <div class="space-y-4">
            <flux:heading size="lg" level="2">{{ __('Deactivated admins') }}</flux:heading>

            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-start text-sm">
                    <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-2 text-start font-medium">{{ __('Name') }}</th>
                            <th class="px-4 py-2 text-start font-medium">{{ __('Email') }}</th>
                            <th class="px-4 py-2 text-start font-medium">{{ __('Deactivated') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->deactivatedAdmins as $admin)
                            <tr wire:key="deactivated-{{ $admin->id }}">
                                <td class="px-4 py-2">{{ $admin->name }}</td>
                                <td class="px-4 py-2">{{ $admin->email }}</td>
                                <td class="px-4 py-2" title="{{ $admin->deactivated_at }}">{{ $admin->deactivated_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <flux:modal name="invite-admin" class="min-w-[22rem] max-w-md">
        <form wire:submit="invite" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Invite an admin') }}</flux:heading>
                <flux:text class="mt-2">{{ __('The invitation link is shown once. It also goes out by email when a mailer is configured.') }}</flux:text>
            </div>

            <flux:input wire:model="email" type="email" :label="__('Email')" required />

            <flux:select wire:model="role" :label="__('Role')">
                @foreach (\App\Enums\AdminRole::cases() as $roleOption)
                    <flux:select.option :value="$roleOption->value">{{ __($roleOption->label()) }} - {{ __($roleOption->summary()) }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Invite') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    @if ($invitationUrl)
        <x-one-time-secret
            :value="$invitationUrl"
            :title="__('Invitation link')"
            :note="$mailSent ? __('This link is shown once. It was also sent by email.') : __('This link is shown once. No email was sent, so pass it on yourself.')"
            close-method="closeInvitationModal"
        />
    @endif
</section>
