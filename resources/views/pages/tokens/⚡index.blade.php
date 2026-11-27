<?php

use App\Models\AccessToken;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Tokens')] class extends Component {
    use WithPagination;

    private const STATUSES = ['active', 'expired', 'revoked'];

    #[Url]
    public string $status = '';

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, AccessToken>
     */
    #[Computed]
    public function tokens(): LengthAwarePaginator
    {
        return AccessToken::query()
            ->with('projects:id,slug')
            ->when(in_array($this->status, self::STATUSES, true), fn ($query) => match ($this->status) {
                'active' => $query->active(),
                'expired' => $query->whereNull('revoked_at')->whereNotNull('expires_at')->where('expires_at', '<=', now()),
                'revoked' => $query->whereNotNull('revoked_at'),
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25);
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Tokens') }}</flux:heading>

        <flux:button variant="primary" icon="plus" :href="route('tokens.create')" wire:navigate>
            {{ __('New token') }}
        </flux:button>
    </div>

    <flux:select wire:model.live="status" class="max-w-56">
        <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
        <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
        <flux:select.option value="expired">{{ __('Expired') }}</flux:select.option>
        <flux:select.option value="revoked">{{ __('Revoked') }}</flux:select.option>
    </flux:select>

    @if ($this->tokens->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
            @if ($status !== '')
                <flux:text>{{ __('No tokens have this status.') }}</flux:text>
            @else
                <flux:text>{{ __('No tokens yet. A token lets one server or pipeline fetch the credentials of its projects.') }}</flux:text>
                <div class="mt-4">
                    <flux:button variant="primary" icon="plus" :href="route('tokens.create')" wire:navigate>
                        {{ __('New token') }}
                    </flux:button>
                </div>
            @endif
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-start text-sm">
                <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Name') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Token') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Projects') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Status') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Expires') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Last used') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->tokens as $token)
                        <tr wire:key="token-{{ $token->id }}">
                            <td class="px-4 py-2">
                                <flux:link :href="route('tokens.show', $token)" wire:navigate>{{ $token->name }}</flux:link>
                            </td>
                            <td class="px-4 py-2 font-mono text-xs">cv_{{ $token->token_prefix }}...</td>
                            <td class="px-4 py-2">{{ $token->projects->pluck('slug')->implode(', ') ?: '-' }}</td>
                            <td class="px-4 py-2">
                                @if ($token->isRevoked())
                                    {{ __('Revoked') }}
                                @elseif ($token->isExpired())
                                    {{ __('Expired') }}
                                @else
                                    {{ __('Active') }}
                                @endif
                            </td>
                            <td class="px-4 py-2">{{ $token->expires_at?->toDateString() ?? __('No expiry') }}</td>
                            <td class="px-4 py-2">
                                @if ($token->last_used_at)
                                    <span title="{{ $token->last_used_at }}">{{ $token->last_used_at->diffForHumans() }}</span>
                                    <span class="font-mono text-xs">{{ $token->last_used_ip }}</span>
                                @else
                                    {{ __('Never used') }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <flux:pagination :paginator="$this->tokens" />
    @endif
</section>
