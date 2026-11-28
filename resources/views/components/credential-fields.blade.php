@props([
    'types',
    'requiresUsername' => false,
    'secretHelp' => null,
])

<flux:field>
    <flux:label>{{ __('Name') }}</flux:label>
    <flux:input wire:model="name" type="text" required />
    <flux:error name="name" />
</flux:field>

<flux:field>
    <flux:label>{{ __('Type') }}</flux:label>
    <flux:select wire:model.live="type">
        @foreach ($types as $case)
            <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
        @endforeach
    </flux:select>
    <flux:error name="type" />
</flux:field>

<flux:field>
    <flux:label>{{ __('Host') }}</flux:label>
    <flux:input wire:model="host" type="text" placeholder="repo.magento.com" required />
    <flux:text class="mt-1">{{ __('Only the hostname. A scheme or a path is removed.') }}</flux:text>
    <flux:error name="host" />
</flux:field>

@if ($requiresUsername)
    <flux:field>
        <flux:label>{{ __('Username') }}</flux:label>
        <flux:input wire:model="username" type="text" autocomplete="off" required />
        <flux:error name="username" />
    </flux:field>
@endif

<flux:field>
    <flux:label>{{ __('Secret') }}</flux:label>
    <flux:input wire:model="secret" type="password" autocomplete="new-password" />
    @if ($secretHelp)
        <flux:text class="mt-1">{{ $secretHelp }}</flux:text>
    @endif
    <flux:error name="secret" />
</flux:field>

<flux:field>
    <flux:label>{{ __('Notes') }}</flux:label>
    <flux:textarea wire:model="notes" rows="3" />
    <flux:error name="notes" />
</flux:field>

