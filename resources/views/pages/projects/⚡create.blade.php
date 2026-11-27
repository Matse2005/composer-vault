<?php

use App\Actions\Vault\CreateProject;
use App\Rules\ProjectSlug;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New project')] class extends Component {
    public string $name = '';

    public string $slug = '';

    public string $notes = '';

    public bool $slugEdited = false;

    /**
     * Fill the slug from the name until the admin edits the slug.
     */
    public function updatedName(): void
    {
        if (! $this->slugEdited) {
            $this->slug = Str::slug($this->name);
        }
    }

    public function updatedSlug(): void
    {
        $this->slugEdited = true;
    }

    public function save(CreateProject $createProject): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', new ProjectSlug, 'unique:projects,slug'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $project = $createProject->handle([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'notes' => $validated['notes'] === '' ? null : $validated['notes'],
        ]);

        session()->flash('flash', __('Project created.'));

        $this->redirectRoute('projects.show', $project, navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('New project') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <flux:field>
            <flux:label>{{ __('Name') }}</flux:label>
            <flux:input wire:model.live.debounce.300ms="name" type="text" required autofocus />
            <flux:error name="name" />
        </flux:field>

        <flux:field>
            <flux:label>{{ __('Slug') }}</flux:label>
            <flux:input wire:model.live.debounce.300ms="slug" type="text" required />
            <flux:text class="mt-1">{{ __('Used in the API and in composer.json. Lowercase letters, digits and hyphens, starting with a letter.') }}</flux:text>
            <flux:error name="slug" />
        </flux:field>

        <flux:field>
            <flux:label>{{ __('Notes') }}</flux:label>
            <flux:textarea wire:model="notes" rows="4" />
            <flux:error name="notes" />
        </flux:field>

        <div class="flex gap-2">
            <flux:button type="submit" variant="primary">{{ __('Create project') }}</flux:button>
            <flux:button variant="ghost" :href="route('projects.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
