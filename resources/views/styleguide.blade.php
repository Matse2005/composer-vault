<x-layouts::app title="Style guide">
    <div class="max-w-5xl space-y-10">
        <div>
            <flux:heading size="xl" level="1">Style guide</flux:heading>
            <flux:text>The design tokens and the component states of the vault, for comparing with the design system. Only available in the local environment.</flux:text>
        </div>

        <section class="space-y-3">
            <flux:heading size="lg" level="2">Colour tokens</flux:heading>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                @foreach (['accent', 'accent-soft', 'surface', 'surface-sunken', 'surface-raised', 'surface-hover', 'border', 'border-strong', 'ink', 'ink-muted', 'ink-subtle', 'success', 'success-soft', 'warning', 'warning-soft', 'danger', 'danger-soft'] as $token)
                    <div class="overflow-hidden rounded-card border border-border" data-test="token-{{ $token }}">
                        <div class="h-12" style="background-color: var(--vault-{{ $token }})"></div>
                        <div class="bg-surface p-2 font-mono text-caption text-ink-muted">{{ $token }}</div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="space-y-3">
            <flux:heading size="lg" level="2">Type scale</flux:heading>
            <p class="text-display text-ink">Display 28/34</p>
            <p class="text-title text-ink">Title 20/28</p>
            <p class="text-heading text-ink">Heading 16/24</p>
            <p class="text-body text-ink">Body 14/20</p>
            <p class="text-small text-ink-muted">Small 13/18</p>
            <p class="text-caption text-ink-subtle">Caption 12/16</p>
            <p class="font-mono text-small text-ink">Mono 13/20, repo.magento.com</p>
        </section>

        <section class="space-y-3">
            <flux:heading size="lg" level="2">Radii</flux:heading>
            <div class="flex gap-4">
                <div class="size-16 rounded-control border border-border-strong bg-surface-raised"></div>
                <div class="size-16 rounded-card border border-border-strong bg-surface-raised"></div>
                <div class="size-16 rounded-panel border border-border-strong bg-surface-raised"></div>
            </div>
        </section>

        <section class="space-y-3">
            <flux:heading size="lg" level="2">Controls</flux:heading>
            <div class="flex flex-wrap items-center gap-3">
                <flux:button variant="primary">Primary</flux:button>
                <flux:button>Default</flux:button>
                <flux:button variant="ghost">Ghost</flux:button>
                <flux:button variant="danger">Danger</flux:button>
                <flux:badge color="green">Active</flux:badge>
                <flux:badge color="amber">Rotation due</flux:badge>
                <flux:badge color="red">Revoked</flux:badge>
            </div>
        </section>
        <section class="space-y-4">
            <flux:heading size="lg" level="2">Components</flux:heading>
            <x-vault.page-header title="Page header" description="Title, description and an action slot.">
                <x-slot:actions><flux:button variant="primary">Action</flux:button></x-slot:actions>
            </x-vault.page-header>
            <div class="grid gap-4 sm:grid-cols-3">
                <x-vault.stat label="Projects" value="12" />
                <x-vault.stat label="Credentials" value="31" hint="3 rotation due" />
                <x-vault.stat label="Active tokens" value="8" />
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach (['active', 'ok', 'warning', 'revoked', 'failed', 'expired', 'unknown'] as $status)
                    <x-vault.status-badge :status="$status" />
                @endforeach
            </div>
            <div class="flex gap-4">
                @foreach (App\Enums\CredentialType::cases() as $type)
                    <div class="flex items-center gap-2"><x-vault.credential-type-icon :type="$type" /><span class="text-small">{{ $type->label() }}</span></div>
                @endforeach
            </div>
            <x-vault.copy-field label="Slug" value="webshop" />
            <x-vault.empty-state icon="key" title="No credentials yet" text="A credential is a key for a host." />
            <x-vault.table :empty="'Nothing here'">
                <x-slot:head><th class="px-4 py-2 text-start font-medium">Name</th><th class="px-4 py-2 text-start font-medium">Host</th></x-slot:head>
                <tr><td class="px-4 py-2">Magento</td><td class="px-4 py-2 font-mono text-caption">repo.magento.com</td></tr>
            </x-vault.table>
        </section>    </div>
</x-layouts::app>