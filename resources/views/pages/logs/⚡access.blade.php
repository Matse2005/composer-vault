<?php

use App\Enums\AccessResult;
use App\Models\AccessLog;
use App\Models\AccessToken;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Access log')] class extends Component {
    use WithPagination;

    #[Url]
    public string $result = '';

    #[Url]
    public string $token = '';

    #[Url]
    public string $project = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['result', 'token', 'project', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Read a Y-m-d day, or null when the value is not a real date.
     */
    private function day(string $value): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    /**
     * @return LengthAwarePaginator<int, AccessLog>
     */
    #[Computed]
    public function logs(): LengthAwarePaginator
    {
        $result = AccessResult::tryFrom($this->result);
        $from = $this->day($this->from);
        $to = $this->day($this->to);

        return AccessLog::query()
            ->with(['accessToken:id,token_prefix', 'project:id,slug'])
            ->when($result !== null, fn ($query) => $query->where('result', $result->value))
            ->when($this->token !== '', fn ($query) => $query->where('access_token_id', $this->token))
            ->when($this->project !== '', fn ($query) => $query->where('project_id', $this->project))
            ->when($from !== null, fn ($query) => $query->where('created_at', '>=', $from->startOfDay()))
            ->when($to !== null, fn ($query) => $query->where('created_at', '<=', $to->endOfDay()))
            ->latest('created_at')
            ->latest('id')
            ->paginate(25);
    }

    /**
     * @return Collection<int, AccessToken>
     */
    #[Computed]
    public function tokens(): Collection
    {
        return AccessToken::query()->orderBy('name')->get(['id', 'name', 'token_prefix']);
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return Project::query()->orderBy('name')->get(['id', 'name', 'slug']);
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl" level="1">{{ __('Access log') }}</flux:heading>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <flux:select wire:model.live="result">
            <flux:select.option value="">{{ __('All results') }}</flux:select.option>
            @foreach (AccessResult::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->value }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="token">
            <flux:select.option value="">{{ __('All tokens') }}</flux:select.option>
            @foreach ($this->tokens as $tokenOption)
                <flux:select.option value="{{ $tokenOption->id }}">{{ $tokenOption->name }} (cv_{{ $tokenOption->token_prefix }}...)</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="project">
            <flux:select.option value="">{{ __('All projects') }}</flux:select.option>
            @foreach ($this->projects as $projectOption)
                <flux:select.option value="{{ $projectOption->id }}">{{ $projectOption->name }} ({{ $projectOption->slug }})</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model.live="from" type="date" :label="__('From')" />
        <flux:input wire:model.live="to" type="date" :label="__('To')" />
    </div>

    @if ($this->logs->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
            <flux:text>{{ __('No requests match. The API writes one row for every request to the composer-auth endpoint.') }}</flux:text>
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-start text-sm">
                <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Time') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Result') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Token') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Project') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('IP') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('User agent') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->logs as $log)
                        <tr wire:key="log-{{ $log->id }}">
                            <td class="px-4 py-2" title="{{ $log->created_at }}">{{ $log->created_at->diffForHumans() }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $log->result->value }}</td>
                            <td class="px-4 py-2 font-mono text-xs">
                                @if ($log->accessToken)
                                    @can('vault.manage')<flux:link :href="route('tokens.show', $log->accessToken)" wire:navigate>cv_{{ $log->accessToken->token_prefix }}...</flux:link>@elsecv_{{ $log->accessToken->token_prefix }}...@endcan
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-4 py-2">{{ $log->project?->slug ?? $log->requested_slug ?? '-' }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $log->ip }}</td>
                            <td class="px-4 py-2 text-xs" title="{{ $log->user_agent }}">{{ Str::limit((string) $log->user_agent, 60) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <flux:pagination :paginator="$this->logs" />
    @endif
</section>
