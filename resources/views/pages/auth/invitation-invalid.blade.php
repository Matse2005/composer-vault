<x-layouts::auth :title="__('Invitation')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Invitation')" :description="\App\Exceptions\Vault\InvalidInvitationException::MESSAGE" />
    </div>
</x-layouts::auth>
