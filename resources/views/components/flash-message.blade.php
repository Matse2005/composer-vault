@if (session('flash'))
    <flux:callout variant="success" icon="check-circle" class="mb-6" data-test="flash-message">
        <flux:callout.heading>{{ session('flash') }}</flux:callout.heading>
    </flux:callout>
@endif
