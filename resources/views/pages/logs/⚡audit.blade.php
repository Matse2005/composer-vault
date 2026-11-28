<?php

use App\Enums\AuditAction;
use App\Models\AccessToken;
use App\Models\AuditEntry;
use App\Models\Credential;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use App\Vault\Audit\AuditChainVerifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Audit log')] class extends Component {
    use WithPagination;

    #[Url]
    public string $action = '';

    /** An admin id, "system" for entries without a causer, or empty for everyone. */
    #[Url]
    public string $actor = '';

    #[Url]
    public string $subjectType = '';

    public ?string $verification = null;

    public bool $chainBroken = false;

    public function updated(string $property): void
    {
        if (in_array($property, ['action', 'actor', 'subjectType'], true)) {
            $this->resetPage();
        }
    }

    /**
     * The subject types that can be filtered on, by short name.
     *
     * @return array<string, class-string<Model>>
     */
    public function subjectTypes(): array
    {
        return [
            'Project' => Project::class,
            'Credential' => Credential::class,
            'AccessToken' => AccessToken::class,
            'Invitation' => Invitation::class,
            'User' => User::class,
        ];
    }

    /**
     * @return LengthAwarePaginator<int, AuditEntry>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        $action = AuditAction::tryFrom($this->action);
        $subjectClass = $this->subjectTypes()[$this->subjectType] ?? null;

        return AuditEntry::query()
            ->with(['causer', 'subject'])
            ->where('log_name', 'audit')
            ->when($action !== null, fn ($query) => $query->where('event', $action->value))
            ->when($this->actor === 'system', fn ($query) => $query->whereNull('causer_id'))
            ->when(ctype_digit($this->actor), fn ($query) => $query->where('causer_type', User::class)->where('causer_id', (int) $this->actor))
            ->when($subjectClass !== null, fn ($query) => $query->where('subject_type', $subjectClass))
            ->orderByDesc('sequence')
            ->paginate(25);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function admins(): Collection
    {
        return User::query()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Run the hash chain verifier and show the outcome. The page only reads: it never changes an entry.
     */
    public function verifyChain(AuditChainVerifier $verifier): void
    {
        $brokenAt = $verifier->verify();

        $this->chainBroken = $brokenAt !== null;
        $this->verification = $brokenAt === null
            ? __('Chain intact (:count)', ['count' => AuditEntry::query()->count().' '.Str::plural('entry', AuditEntry::query()->count())])
            : __('Chain broken at entry :sequence', ['sequence' => $brokenAt]);
    }

    /**
     * A readable name for the subject of an entry: its current name when it still exists, otherwise the label that
     * was stored with the entry.
     */
    public function subjectLabel(AuditEntry $entry): string
    {
        $subject = $entry->subject;

        return match (true) {
            $subject instanceof Project => $subject->slug,
            $subject instanceof Credential, $subject instanceof AccessToken, $subject instanceof User => $subject->name,
            $subject instanceof Invitation => __('Invitation'),
            default => (string) ($entry->properties['subject_label'] ?? $entry->subject_id),
        };
    }

    /**
     * The page of an existing subject, or null when there is none.
     */
    public function subjectUrl(?Model $subject): ?string
    {
        $canManageVault = auth()->user()?->can('vault.manage') === true;
        $canManageAdmins = auth()->user()?->can('admins.manage') === true;

        return match (true) {
            $subject instanceof Project && $canManageVault => route('projects.show', $subject),
            $subject instanceof Credential && $canManageVault => route('credentials.edit', $subject),
            $subject instanceof AccessToken && $canManageVault => route('tokens.show', $subject),
            ($subject instanceof Invitation || $subject instanceof User) && $canManageAdmins => route('admins.index'),
            default => null,
        };
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Audit log') }}</flux:heading>

        <flux:button wire:click="verifyChain" icon="shield-check">{{ __('Verify chain') }}</flux:button>
    </div>

    @if ($verification)
        <flux:callout :variant="$chainBroken ? 'danger' : 'success'" :icon="$chainBroken ? 'x-circle' : 'check-circle'" data-test="chain-verification">
            <flux:callout.heading>{{ $verification }}</flux:callout.heading>
        </flux:callout>
    @endif

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:select wire:model.live="action">
            <flux:select.option value="">{{ __('All actions') }}</flux:select.option>
            @foreach (AuditAction::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->value }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="actor">
            <flux:select.option value="">{{ __('Everyone') }}</flux:select.option>
            <flux:select.option value="system">{{ __('System') }}</flux:select.option>
            @foreach ($this->admins as $admin)
                <flux:select.option value="{{ $admin->id }}">{{ $admin->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="subjectType">
            <flux:select.option value="">{{ __('All subjects') }}</flux:select.option>
            @foreach (array_keys($this->subjectTypes()) as $type)
                <flux:select.option value="{{ $type }}">{{ $type }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($this->entries->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
            <flux:text>{{ __('No audit entries match. Every change made in the admin interface is recorded here.') }}</flux:text>
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-start text-sm">
                <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-2 text-start font-medium">#</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Time') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Actor') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Action') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Subject') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('IP') }}</th>
                    </tr>
                </thead>
                @foreach ($this->entries as $entry)
                    <tbody wire:key="entry-{{ $entry->id }}" x-data="{ open: false }" class="border-t border-zinc-200 dark:border-zinc-700">
                        <tr class="cursor-pointer" x-on:click="open = !open">
                            <td class="px-4 py-2 font-mono text-xs">#{{ $entry->sequence }}</td>
                            <td class="px-4 py-2" title="{{ $entry->created_at }}">{{ $entry->created_at->diffForHumans() }}</td>
                            <td class="px-4 py-2">{{ $entry->causer?->name ?? __('System') }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $entry->event }}</td>
                            <td class="px-4 py-2">
                                @php($subjectUrl = $this->subjectUrl($entry->subject))
                                @if ($entry->subject_type)
                                    <span class="text-zinc-500">{{ class_basename($entry->subject_type) }}</span>
                                    @if ($subjectUrl)
                                        <flux:link :href="$subjectUrl" wire:navigate>{{ $this->subjectLabel($entry) }}</flux:link>
                                    @else
                                        {{ $this->subjectLabel($entry) }}
                                    @endif
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $entry->ip ?? '-' }}</td>
                        </tr>
                        <tr x-show="open" x-cloak>
                            <td colspan="6" class="bg-zinc-50 px-4 py-3 dark:bg-zinc-900">
                                <pre class="overflow-x-auto text-xs">{{ json_encode($entry->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                            </td>
                        </tr>
                    </tbody>
                @endforeach
            </table>
        </div>

        <flux:pagination :paginator="$this->entries" />
    @endif
</section>
