<?php

use App\Actions\Vault\DeleteRole;
use App\Actions\Vault\SaveRole;
use App\Exceptions\Vault\ChangeProposedException;
use App\Exceptions\Vault\RoleEscalationException;
use App\Models\Role;
use App\Vault\Authorization\Ability;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Roles')] class extends Component {
    public ?string $roleId = null;

    public string $name = '';

    /** @var list<string> */
    public array $permissions = [];

    public string $ssoGroups = '';

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function roles(): Collection
    {
        return Role::query()->withCount('users')->orderByDesc('is_system')->orderBy('name')->get();
    }

    #[Computed]
    public function selected(): ?Role
    {
        return $this->roleId === null ? null : Role::query()->find($this->roleId);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function groups(): array
    {
        return Ability::groups();
    }

    public function select(?string $id): void
    {
        $this->resetErrorBag();
        $this->roleId = $id;
        unset($this->selected);

        $role = $this->selected;

        $this->name = $role?->name ?? '';
        $this->permissions = $role === null ? [] : ($role->isOwner() ? Ability::all() : array_values($role->permissions));
        $this->ssoGroups = $role === null ? '' : implode(', ', (array) $role->sso_groups);
    }

    public function save(SaveRole $saveRole): void
    {
        $this->resetErrorBag();
        $this->validate(['name' => ['required', 'string', 'max:100']]);

        try {
            $role = $saveRole->handle($this->selected, $this->name, $this->permissions, explode(',', $this->ssoGroups), auth()->user());
        } catch (ChangeProposedException $exception) {
            Flux::toast($exception->getMessage());

            return;
        } catch (RoleEscalationException $exception) {
            $this->addError('permissions', $exception->getMessage());

            return;
        }

        unset($this->roles);
        $this->select($role->id);
        Flux::toast(__('Role saved.'));
    }

    public function delete(DeleteRole $deleteRole): void
    {
        $role = $this->selected;

        if ($role === null) {
            return;
        }

        try {
            $deleteRole->handle($role, auth()->user());
        } catch (RoleEscalationException $exception) {
            $this->addError('permissions', $exception->getMessage());
            Flux::modals()->close();

            return;
        }

        unset($this->roles);
        Flux::modals()->close();
        $this->select(null);
        Flux::toast(__('Role deleted.'));
    }
}; ?>

<section class="w-full max-w-5xl space-y-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Roles') }}</flux:heading>
        <flux:button variant="primary" icon="plus" wire:click="select(null)">{{ __('New role') }}</flux:button>
    </div>

    <flux:text>{{ __('A role is a set of permissions. Owner always has every permission and cannot be changed. You can only give permissions you have yourself, and you cannot change your own role.') }}</flux:text>

    <ul class="divide-y divide-border rounded-panel border border-border">
        @foreach ($this->roles as $role)
            <li wire:key="role-{{ $role->id }}" class="flex items-center justify-between px-4 py-2">
                <button type="button" wire:click="select('{{ $role->id }}')" class="cursor-pointer text-start font-medium">{{ $role->name }}</button>
                <span class="text-sm">
                    {{ $role->is_system ? __('System role') : __('Custom role') }},
                    {{ $role->isOwner() ? __('every permission') : trans_choice(':count permission|:count permissions', count($role->permissions)) }}
                </span>
            </li>
        @endforeach
    </ul>

    <form wire:submit="save" class="space-y-6 rounded-panel border border-border p-5">
        <flux:heading size="lg" level="2">{{ $this->selected ? __('Edit :name', ['name' => $this->selected->name]) : __('New role') }}</flux:heading>

        @if ($this->selected?->isOwner())
            <flux:callout variant="secondary" icon="lock-closed"><flux:callout.text>{{ __('The Owner role is fixed. Only the identity provider groups below can be changed in the environment.') }}</flux:callout.text></flux:callout>
        @endif

        <flux:input wire:model="name" :label="__('Name')" :disabled="$this->selected?->is_system" required />
        <flux:error name="name" />

        <div class="space-y-4">
            @foreach ($this->groups() as $group => $items)
                <fieldset wire:key="group-{{ $group }}" class="space-y-2">
                    <legend class="text-sm font-medium">{{ __($group) }}</legend>
                    @foreach ($items as $permission => $description)
                        <flux:checkbox wire:model="permissions" :value="$permission" :label="$permission" :description="__($description)" :disabled="$this->selected?->isOwner()" wire:key="permission-{{ $permission }}" />
                    @endforeach
                </fieldset>
            @endforeach
            <flux:error name="permissions" />
        </div>

        <flux:input wire:model="ssoGroups" :label="__('Identity provider groups')" :description="__('Comma separated group names that give this role at sign-in. Owner, Admin and Auditor can also be set with VAULT_SSO_OWNER_GROUPS, VAULT_SSO_ADMIN_GROUPS and VAULT_SSO_AUDITOR_GROUPS, which win over this field.')" :disabled="$this->selected?->isOwner()" />

        <div class="flex gap-2">
            @unless ($this->selected?->isOwner())
                <flux:button type="submit" variant="primary">{{ __('Save role') }}</flux:button>
            @endunless
            @if ($this->selected && ! $this->selected->is_system)
                <flux:modal.trigger name="delete-role"><flux:button variant="danger">{{ __('Delete role') }}</flux:button></flux:modal.trigger>
            @endif
        </div>
    </form>

    <flux:modal name="delete-role" class="min-w-[22rem] max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this role?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('A role that an admin or an invitation uses cannot be deleted. Give them another role first.') }}</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button wire:click="delete" variant="danger">{{ __('Delete role') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
