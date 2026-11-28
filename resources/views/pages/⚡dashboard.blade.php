<?php

use App\Enums\AccessResult;
use App\Models\AccessLog;
use App\Models\AccessToken;
use App\Models\Credential;
use App\Models\Project;
use App\Vault\Health\HealthRunner;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    #[Computed]
    public function projectCount(): int
    {
        return Project::query()->count();
    }

    #[Computed]
    public function credentialCount(): int
    {
        return Credential::query()->count();
    }

    #[Computed]
    public function activeTokenCount(): int
    {
        return AccessToken::query()->active()->count();
    }

    #[Computed]
    public function failedRequestCount(): int
    {
        return AccessLog::query()
            ->where('result', '!=', AccessResult::Ok->value)
            ->where('created_at', '>=', now()->subDay())
            ->count();
    }

    /**
     * One line about the latest health run.
     */
    #[Computed]
    public function healthSummary(): string
    {
        $latest = app(HealthRunner::class)->latest();

        if ($latest === null) {
            return __('No health results yet, see Security status');
        }

        $problems = HealthRunner::problemCount($latest['rows']);

        return $problems === 0
            ? __('All checks pass')
            : trans_choice(':count problem, see Security status|:count problems, see Security status', $problems);
    }

    /**
     * @return Collection<int, AccessLog>
     */
    #[Computed]
    public function recentFailures(): Collection
    {
        return AccessLog::query()
            ->with(['accessToken:id,token_prefix', 'project:id,slug'])
            ->where('result', '!=', AccessResult::Ok->value)
            ->latest('created_at')
            ->latest('id')
            ->limit(10)
            ->get();
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl" level="1">{{ __('Dashboard') }}</flux:heading>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <a href="{{ route('projects.index') }}" wire:navigate class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:text>{{ __('Projects') }}</flux:text>
            <flux:heading size="xl" data-test="project-count">{{ $this->projectCount }}</flux:heading>
        </a>
        <a href="{{ route('projects.index') }}" wire:navigate class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:text>{{ __('Credentials') }}</flux:text>
            <flux:heading size="xl" data-test="credential-count">{{ $this->credentialCount }}</flux:heading>
        </a>
        <a href="{{ route('projects.index') }}" wire:navigate class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:text>{{ __('Active tokens') }}</flux:text>
            <flux:heading size="xl" data-test="active-token-count">{{ $this->activeTokenCount }}</flux:heading>
        </a>
        <a href="{{ route('logs.access') }}" wire:navigate class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:text>{{ __('Failed API requests (24 hours)') }}</flux:text>
            <flux:heading size="xl" data-test="failed-request-count">{{ $this->failedRequestCount }}</flux:heading>
        </a>
    </div>

    <flux:link :href="route('security-status')" wire:navigate data-test="health-summary">{{ $this->healthSummary }}</flux:link>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Recent failed API requests') }}</flux:heading>

        @if ($this->recentFailures->isEmpty())
            <flux:text>{{ __('No failed API requests.') }}</flux:text>
        @else
            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-start text-sm">
                    <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-2 text-start font-medium">{{ __('Result') }}</th>
                            <th class="px-4 py-2 text-start font-medium">{{ __('Project') }}</th>
                            <th class="px-4 py-2 text-start font-medium">{{ __('Token') }}</th>
                            <th class="px-4 py-2 text-start font-medium">{{ __('IP') }}</th>
                            <th class="px-4 py-2 text-start font-medium">{{ __('Time') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->recentFailures as $log)
                            <tr wire:key="failure-{{ $log->id }}">
                                <td class="px-4 py-2 font-mono text-xs">{{ $log->result->value }}</td>
                                <td class="px-4 py-2">{{ $log->project?->slug ?? $log->requested_slug ?? '-' }}</td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $log->accessToken?->token_prefix ?? '-' }}</td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $log->ip }}</td>
                                <td class="px-4 py-2" title="{{ $log->created_at }}">{{ $log->created_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</section>
