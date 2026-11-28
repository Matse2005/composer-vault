<x-layouts::auth :title="__('Sign-in failed')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Sign-in failed')" :description="__('The sign-in through your identity provider did not work. Try again, or ask an Owner of this vault.')" />

        <flux:button variant="primary" :href="route('login')" class="w-full">{{ __('Back to the login page') }}</flux:button>
    </div>
</x-layouts::auth>
