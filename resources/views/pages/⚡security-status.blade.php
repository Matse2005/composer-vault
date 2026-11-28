<?php

use App\Vault\Health\HealthRunner;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Security status')] class extends Component {
    /**
     * The latest stored run, or null when no check has run yet.
     *
     * @return array{finished_at: DateTimeInterface, rows: array<int, array{name: string, label: string, status: string, summary: string, fix: string}>}|null
     */
    #[Computed]
    public function report(): ?array
    {
        return app(HealthRunner::class)->latest();
    }

    /**
     * Run every check now, including the audit chain, and show the new results.
     */
    public function runChecks(HealthRunner $runner): void
    {
        $runner->runFresh();

        unset($this->report);
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            'ok' => 'green',
            'warning' => 'amber',
            'failed', 'crashed' => 'red',
            default => 'zinc',
        };
    }
}; ?>

<section class="w-full max-w-5xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Security status') }}</flux:heading>

        <flux:button wire:click="runChecks" icon="arrow-path" wire:loading.attr="disabled" wire:target="runChecks">
            {{ __('Run all checks now') }}
        </flux:button>
    </div>

    @if ($this->report === null)
        <div class="rounded-panel border border-dashed border-border-strong p-10 text-center">
            <flux:text>{{ __('No checks have run yet. Run them now, or wait for the scheduler.') }}</flux:text>
        </div>
    @else
        <flux:text data-test="finished-at" title="{{ $this->report['finished_at']->format('Y-m-d H:i:s') }}">
            {{ __('Last run :time', ['time' => $this->report['finished_at']->diffForHumans()]) }}
        </flux:text>

        <div class="overflow-x-auto rounded-panel border border-border">
            <table class="w-full text-start text-sm">
                <thead class="bg-surface-sunken text-ink-muted">
                    <tr>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Status') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Check') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Summary') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Fix') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($this->report['rows'] as $row)
                        <tr wire:key="check-{{ $row['name'] }}">
                            <td class="px-4 py-2"><flux:badge size="sm" :color="$this->statusColor($row['status'])">{{ $row['status'] }}</flux:badge></td>
                            <td class="px-4 py-2">{{ $row['label'] }}</td>
                            <td class="px-4 py-2">{{ $row['summary'] }}</td>
                            <td class="px-4 py-2">{{ $row['fix'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
