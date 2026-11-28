<?php

use App\Actions\Vault\RevokeTokensInBulk;
use App\Actions\Vault\SaveIncident;
use App\Actions\Vault\SetLockdown;
use App\Actions\Vault\SignOutAdmins;
use App\Models\Incident;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Incidents')] class extends Component {
    /** The word the Owner types to confirm an emergency action. */
    private const WORDS = [
        'revoke-project' => 'REVOKE',
        'revoke-all' => 'REVOKE-ALL',
        'sign-out' => 'SIGN-OUT',
        'lockdown-on' => 'LOCKDOWN',
        'lockdown-off' => 'UNLOCK',
    ];

    public string $confirmation = '';

    public string $projectId = '';

    public ?string $selectedId = null;

    public string $title = '';

    public string $detectedAt = '';

    public string $closedAt = '';

    public string $notes = '';

    public ?string $result = null;

    public function mount(): void
    {
        $this->detectedAt = now()->format('Y-m-d\TH:i');
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return Project::query()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return Collection<int, Incident>
     */
    #[Computed]
    public function incidents(): Collection
    {
        return Incident::query()->orderByDesc('detected_at')->get();
    }

    #[Computed]
    public function selected(): ?Incident
    {
        return $this->selectedId === null ? null : Incident::query()->find($this->selectedId);
    }

    public function lockdownIsOn(): bool
    {
        return SetLockdown::isOn();
    }

    public function word(string $action): string
    {
        return self::WORDS[$action];
    }

    public function emergency(string $action): void
    {
        abort_unless(array_key_exists($action, self::WORDS), 404);

        if (trim($this->confirmation) !== self::WORDS[$action]) {
            $this->addError('confirmation', __('Type :word to confirm.', ['word' => self::WORDS[$action]]));

            return;
        }

        $actor = auth()->user();

        $this->result = match ($action) {
            'revoke-project' => $this->revokeProject($actor),
            'revoke-all' => __(':count token(s) revoked.', ['count' => app(RevokeTokensInBulk::class)->handle($actor)]),
            'sign-out' => __(':count session(s) ended.', ['count' => app(SignOutAdmins::class)->handle($actor)]),
            'lockdown-on' => $this->lockdown(true, $actor),
            'lockdown-off' => $this->lockdown(false, $actor),
        };

        $this->reset('confirmation');
        Flux::modals()->close();
        Flux::toast($this->result);
    }

    private function revokeProject(App\Models\User $actor): string
    {
        $project = Project::query()->findOrFail($this->projectId);

        return __(':count token(s) of :project revoked.', ['count' => app(RevokeTokensInBulk::class)->handle($actor, $project), 'project' => $project->name]);
    }

    private function lockdown(bool $on, App\Models\User $actor): string
    {
        app(SetLockdown::class)->handle($on, $actor);

        return $on ? __('Lockdown is on.') : __('Lockdown is lifted.');
    }

    public function select(?string $id): void
    {
        $this->selectedId = $id;
        $incident = $this->selected;

        $this->title = $incident?->title ?? '';
        $this->detectedAt = ($incident?->detected_at ?? now())->format('Y-m-d\TH:i');
        $this->closedAt = $incident?->closed_at?->format('Y-m-d\TH:i') ?? '';
        $this->notes = (string) $incident?->notes;
        unset($this->selected);
    }

    public function saveIncident(SaveIncident $saveIncident): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'detectedAt' => ['required', 'date'],
            'closedAt' => ['nullable', 'date', 'after_or_equal:detectedAt'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $incident = $saveIncident->handle(
            $this->selected,
            $this->title,
            Carbon::parse($this->detectedAt),
            $this->closedAt === '' ? null : Carbon::parse($this->closedAt),
            $this->notes,
            auth()->user(),
        );

        unset($this->incidents);
        $this->select($incident->id);
        Flux::toast(__('Incident saved.'));
    }
}; ?>

<section class="w-full max-w-5xl space-y-10">
    <flux:heading size="xl" level="1">{{ __('Incidents') }}</flux:heading>

    @if ($result)
        <flux:callout variant="success" icon="check-circle" data-test="result"><flux:callout.text>{{ $result }}</flux:callout.text></flux:callout>
    @endif

    <div class="space-y-4">
        <flux:heading size="lg" level="2">{{ __('Emergency actions') }}</flux:heading>
        <flux:text>{{ __('Each action asks you to type a word first. Every action is audited with the number of items it affected.') }}</flux:text>

        @if ($this->lockdownIsOn())
            <flux:callout variant="danger" icon="lock-closed"><flux:callout.heading>{{ __('Lockdown is on') }}</flux:callout.heading><flux:callout.text>{{ __('Only Owners can use the admin interface.') }}</flux:callout.text></flux:callout>
        @endif

        <div class="flex flex-wrap gap-3">
            <flux:modal.trigger name="emergency-revoke-project"><flux:button variant="danger">{{ __('Revoke the tokens of a project') }}</flux:button></flux:modal.trigger>
            <flux:modal.trigger name="emergency-revoke-all"><flux:button variant="danger">{{ __('Revoke every token') }}</flux:button></flux:modal.trigger>
            <flux:modal.trigger name="emergency-sign-out"><flux:button variant="danger">{{ __('Sign out every other admin') }}</flux:button></flux:modal.trigger>
            @if ($this->lockdownIsOn())
                <flux:modal.trigger name="emergency-lockdown-off"><flux:button>{{ __('Lift the lockdown') }}</flux:button></flux:modal.trigger>
            @else
                <flux:modal.trigger name="emergency-lockdown-on"><flux:button variant="danger">{{ __('Lock down to Owners') }}</flux:button></flux:modal.trigger>
            @endif
        </div>
    </div>

    @foreach ([
        'revoke-project' => ['Revoke the tokens of a project', 'Every token that can fetch the chosen project stops working at once.'],
        'revoke-all' => ['Revoke every token', 'Every token stops working at once. Servers and pipelines lose access until they get a new token.'],
        'sign-out' => ['Sign out every other admin', 'Their sessions end and their remember me tokens stop. You stay signed in. This needs the database session driver.'],
        'lockdown-on' => ['Lock down to Owners', 'Admins and Auditors get a 403 on every page until you lift the lockdown. Their sessions stay, but they cannot use them.'],
        'lockdown-off' => ['Lift the lockdown', 'Admins and Auditors can use the admin interface again.'],
    ] as $action => [$heading, $explanation])
        <flux:modal :name="'emergency-'.$action" class="min-w-[22rem] max-w-md" wire:key="modal-{{ $action }}">
            <form wire:submit="emergency('{{ $action }}')" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __($heading) }}</flux:heading>
                    <flux:text class="mt-2">{{ __($explanation) }}</flux:text>
                </div>

                @if ($action === 'revoke-project')
                    <flux:select wire:model="projectId" :label="__('Project')" required>
                        <flux:select.option value="">{{ __('Choose a project') }}</flux:select.option>
                        @foreach ($this->projects as $project)
                            <flux:select.option :value="$project->id">{{ $project->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:input wire:model="confirmation" :label="__('Type :word to confirm', ['word' => $this->word($action)])" autocomplete="off" />
                <flux:error name="confirmation" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger">{{ __($heading) }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endforeach

    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <flux:heading size="lg" level="2">{{ __('Incident records') }}</flux:heading>
            <flux:button size="sm" wire:click="select(null)" icon="plus">{{ __('New incident') }}</flux:button>
        </div>

        @if ($this->incidents->isEmpty())
            <flux:text>{{ __('No incidents recorded.') }}</flux:text>
        @else
            <ul class="divide-y divide-border rounded-panel border border-border">
                @foreach ($this->incidents as $incident)
                    <li wire:key="incident-{{ $incident->id }}" class="flex items-center justify-between px-4 py-2">
                        <button type="button" wire:click="select('{{ $incident->id }}')" class="cursor-pointer text-start font-medium">{{ $incident->title }}</button>
                        <span class="text-sm" title="{{ $incident->detected_at }}">{{ $incident->closed_at ? __('Closed') : __('Open') }}, {{ $incident->detected_at->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <form wire:submit="saveIncident" class="space-y-4 rounded-panel border border-border p-5">
            <flux:heading size="md" level="3">{{ $selectedId ? __('Edit incident') : __('New incident') }}</flux:heading>
            <flux:input wire:model="title" :label="__('Title')" required />
            <flux:input wire:model="detectedAt" type="datetime-local" :label="__('Detected at')" required />
            <flux:input wire:model="closedAt" type="datetime-local" :label="__('Closed at')" />
            <flux:textarea wire:model="notes" rows="5" :label="__('Notes')" :description="__('Notes are not written to the audit log. Keep names out of them when you can.')" />
            <flux:error name="title" /><flux:error name="detectedAt" /><flux:error name="closedAt" />
            <flux:button type="submit" variant="primary">{{ __('Save incident') }}</flux:button>
        </form>

        @if ($this->selected)
            <div class="space-y-3 rounded-panel border border-border p-5" data-test="reference-times">
                <flux:heading size="md" level="3">{{ __('Reference times') }}</flux:heading>
                <ul class="text-sm">
                    @foreach ($this->selected->referenceTimes() as $label => $time)
                        <li>{{ __($label) }}: {{ $time->format('Y-m-d H:i') }}</li>
                    @endforeach
                </ul>
                <flux:text class="text-xs">
                    {{ __('These are counted from the time of detection, as points to check your reporting duties against. Whether and to whom an incident must be reported is decided by your company and the applicable law. The time limits were not checked against the text of the directive by this software, so confirm them yourself.') }}
                </flux:text>
                <div class="flex gap-2">
                    <flux:button size="sm" :href="route('incidents.timeline', [$this->selected, 'json'])" icon="arrow-down-tray">{{ __('Timeline as JSON') }}</flux:button>
                    <flux:button size="sm" :href="route('incidents.timeline', [$this->selected, 'csv'])" icon="arrow-down-tray">{{ __('Timeline as CSV') }}</flux:button>
                </div>
            </div>
        @endif
    </div>
</section>
