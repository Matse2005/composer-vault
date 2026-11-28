<?php

use App\Models\AuditEntry;
use App\Vault\Settings\SettingsRegistry;
use App\Vault\Settings\VaultSettings;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Settings history')] class extends Component {
    use WithPagination;

    /**
     * @return LengthAwarePaginator<int, AuditEntry>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        return AuditEntry::query()->where('event', 'settings_changed')->orderByDesc('sequence')->paginate(15);
    }

    /**
     * Determine whether a change can be restored by the current admin: the setting exists, is not a contact detail
     * (those are audited as "changed" only), is not locked by the environment, and the admin may change it.
     */
    public function canRestore(string $key, mixed $old): bool
    {
        if (! SettingsRegistry::has($key) || SettingsRegistry::get($key)->isSecret || $old === 'changed') {
            return false;
        }

        return app(VaultSettings::class)->isFromEnvironment($key) === false && (auth()->user()?->can(SettingsRegistry::get($key)->permission) ?? false);
    }

    public function restore(int $sequence, string $key): void
    {
        $entry = AuditEntry::query()->where('event', 'settings_changed')->where('sequence', $sequence)->firstOrFail();
        $change = $entry->properties['changes'][$key] ?? null;

        if (! is_array($change) || ! $this->canRestore($key, $change['old'] ?? null)) {
            $this->addError('restore', __('This value cannot be restored.'));

            return;
        }

        try {
            app(VaultSettings::class)->update([$key => $change['old']], auth()->user());
        } catch (AuthorizationException|ValidationException $exception) {
            $this->addError('restore', __('This value cannot be restored.'));

            return;
        }

        Flux::toast(__('Value restored.'));
    }
}; ?>

<section class="w-full max-w-5xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Settings history') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Every change of a setting with the old and the new value. Contact details and secrets are never shown. Restoring a value is a normal change: it needs the same permission and is audited.') }}</flux:text>
    </div>

    <flux:error name="restore" />

    @forelse ($this->entries as $entry)
        <div wire:key="entry-{{ $entry->sequence }}" class="space-y-2 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="text-sm text-zinc-600 dark:text-zinc-400" title="{{ $entry->created_at }}">#{{ $entry->sequence }}, {{ $entry->created_at?->diffForHumans() }}</div>
            @foreach ((array) ($entry->properties['changes'] ?? []) as $key => $change)
                <div wire:key="change-{{ $entry->sequence }}-{{ $key }}" class="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span><span class="font-mono">{{ $key }}</span>: {{ is_scalar($change['old'] ?? null) || ($change['old'] ?? null) === null ? json_encode($change['old'] ?? null) : '...' }} &rarr; {{ is_scalar($change['new'] ?? null) || ($change['new'] ?? null) === null ? json_encode($change['new'] ?? null) : '...' }}</span>
                    @if ($this->canRestore($key, $change['old'] ?? null))
                        <flux:button size="xs" wire:click="restore({{ $entry->sequence }}, '{{ $key }}')">{{ __('Restore this value') }}</flux:button>
                    @endif
                </div>
            @endforeach
        </div>
    @empty
        <flux:text>{{ __('No setting has been changed yet.') }}</flux:text>
    @endforelse

    {{ $this->entries->links() }}
</section>
