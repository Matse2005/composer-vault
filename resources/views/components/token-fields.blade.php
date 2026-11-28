<flux:field>
    <flux:label>{{ __('Name') }}</flux:label>
    <flux:input wire:model="name" type="text" placeholder="prod-web-01" required />
    <flux:error name="name" />
</flux:field>

<flux:field>
    <flux:label>{{ __('Allowed IP addresses') }}</flux:label>
    <flux:textarea wire:model="allowedIps" rows="4" placeholder="203.0.113.7&#10;10.0.0.0/8" />
    <flux:text class="mt-1">{{ __('One IP address or CIDR range per line. Leave empty to allow every address.') }}</flux:text>
    <flux:error name="allowedIps" />
</flux:field>

<flux:field>
    <flux:label>{{ __('Expires on') }}</flux:label>
    <flux:input wire:model="expiresOn" type="date" />
    <flux:text class="mt-1">{{ __('Optional. The token stops working at the end of this day.') }}</flux:text>
    <flux:error name="expiresOn" />
</flux:field>
