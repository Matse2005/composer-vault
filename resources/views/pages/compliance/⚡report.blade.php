<?php

use App\Vault\Compliance\ComplianceReport;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Compliance report')] class extends Component {
    /**
     * @return list<array{control: string, status: App\Enums\ControlStatus, evidence: string, supports: string}>
     */
    public function controls(): array
    {
        return app(ComplianceReport::class)->controls();
    }
}; ?>

<section class="w-full max-w-6xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Compliance report') }}</flux:heading>
            <flux:text class="mt-2" data-test="disclaimer">{{ \App\Vault\Compliance\ComplianceReport::DISCLAIMER }}</flux:text>
            <flux:text class="mt-1 text-xs">{{ __('Composer Vault :version, :date. The topics are named without article numbers because this software did not check the article texts.', ['version' => config('vault.version'), 'date' => now()->format('Y-m-d')]) }}</flux:text>
        </div>
        <flux:button class="print:hidden" icon="printer" x-on:click="window.print()">{{ __('Print') }}</flux:button>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-start text-sm">
            <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-2 text-start font-medium">{{ __('Control') }}</th>
                    <th class="px-4 py-2 text-start font-medium">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-start font-medium">{{ __('Evidence') }}</th>
                    <th class="px-4 py-2 text-start font-medium">{{ __('Supports') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->controls() as $row)
                    <tr wire:key="control-{{ $loop->index }}">
                        <td class="px-4 py-2 font-medium">{{ __($row['control']) }}</td>
                        <td class="px-4 py-2">
                            <flux:badge size="sm" :color="match ($row['status']->value) { 'in_place' => 'green', 'partly' => 'amber', default => 'red' }">{{ __($row['status']->label()) }}</flux:badge>
                        </td>
                        <td class="px-4 py-2">{{ $row['evidence'] }}</td>
                        <td class="px-4 py-2">{{ __($row['supports']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
