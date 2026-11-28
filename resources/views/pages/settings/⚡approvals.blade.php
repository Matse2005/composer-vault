<?php

use App\Actions\Vault\DecidePendingChange;
use App\Exceptions\Vault\ApprovalRefusedException;
use App\Models\PendingChange;
use App\Vault\Settings\ChangeApproval;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Approvals')] class extends Component {
    /**
     * @return Collection<int, PendingChange>
     */
    #[Computed]
    public function pending(): Collection
    {
        return PendingChange::query()->with('proposer:id,name')->where('status', 'pending')->where('expires_at', '>', now())->orderBy('expires_at')->get();
    }

    /**
     * @return Collection<int, PendingChange>
     */
    #[Computed]
    public function decided(): Collection
    {
        return PendingChange::query()->with('proposer:id,name')->where(fn ($query) => $query->where('status', '!=', 'pending')->orWhere('expires_at', '<=', now()))->latest()->limit(20)->get();
    }

    public function oneOwnerOnly(): bool
    {
        return app(ChangeApproval::class)->isOnWithOneOwner();
    }

    public function describe(PendingChange $change): string
    {
        return match ($change->kind) {
            'setting' => __('Setting :key', ['key' => $change->subject]).': '.(is_scalar($change->payload['value'] ?? null) ? json_encode($change->payload['value']) : '...'),
            'secret' => __('A new value for the secret :key (never shown)', ['key' => $change->subject]),
            'role' => __('The permissions of a role: :permissions', ['permissions' => implode(', ', (array) ($change->payload['permissions'] ?? []))]),
            'owner' => __('Giving the Owner role to admin #:id', ['id' => $change->subject]),
            default => $change->kind,
        };
    }

    public function approve(string $id, DecidePendingChange $decide): void
    {
        $this->decide(fn () => $decide->approve(PendingChange::query()->findOrFail($id), auth()->user()), __('Change approved and applied.'));
    }

    public function reject(string $id, DecidePendingChange $decide): void
    {
        $this->decide(fn () => $decide->reject(PendingChange::query()->findOrFail($id), auth()->user()), __('Change rejected.'));
    }

    private function decide(\Closure $action, string $message): void
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (ApprovalRefusedException $exception) {
            $this->addError('decision', $exception->getMessage());

            return;
        }

        unset($this->pending, $this->decided);
        Flux::toast($message);
    }
}; ?>

<section class="w-full max-w-4xl space-y-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Approvals') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Critical changes wait here for an Owner other than the one who proposed them. A change that nobody decides within 24 hours expires.') }}</flux:text>
    </div>

    @if ($this->oneOwnerOnly())
        <flux:callout variant="secondary" icon="information-circle"><flux:callout.text>{{ __('Approval is on, but only one Owner is active, so changes apply directly. Invite a second Owner to use approvals.') }}</flux:callout.text></flux:callout>
    @endif

    <flux:error name="decision" />

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Waiting') }}</flux:heading>
        @forelse ($this->pending as $change)
            <div wire:key="pending-{{ $change->id }}" class="flex flex-wrap items-center justify-between gap-3 rounded-panel border border-border p-4">
                <div>
                    <div class="font-medium">{{ $this->describe($change) }}</div>
                    <div class="text-sm text-ink-muted">{{ __('Proposed by :name, expires :time', ['name' => $change->proposer?->name ?? __('a removed admin'), 'time' => $change->expires_at->diffForHumans()]) }}</div>
                </div>
                <div class="flex gap-2">
                    @if ($change->proposed_by !== auth()->id())
                        <flux:button size="sm" variant="primary" wire:click="approve('{{ $change->id }}')">{{ __('Approve') }}</flux:button>
                    @endif
                    <flux:button size="sm" wire:click="reject('{{ $change->id }}')">{{ $change->proposed_by === auth()->id() ? __('Withdraw') : __('Reject') }}</flux:button>
                </div>
            </div>
        @empty
            <flux:text>{{ __('Nothing is waiting.') }}</flux:text>
        @endforelse
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Recently decided') }}</flux:heading>
        @forelse ($this->decided as $change)
            <div wire:key="decided-{{ $change->id }}" class="flex justify-between rounded-card border border-border px-4 py-2 text-sm">
                <span>{{ $this->describe($change) }}</span>
                <span>{{ $change->status === 'pending' ? __('expired') : __($change->status) }}</span>
            </div>
        @empty
            <flux:text>{{ __('No change was decided yet.') }}</flux:text>
        @endforelse
    </div>
</section>