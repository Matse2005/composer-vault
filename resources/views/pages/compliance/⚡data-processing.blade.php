<?php

use App\Vault\Settings\VaultSettings;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Data processing')] class extends Component {
    /**
     * The categories of personal data the vault processes, where each is stored and how long it is kept.
     *
     * @return list<array{category: string, data: string, stored: string, retention: string}>
     */
    public function categories(): array
    {
        $settings = app(VaultSettings::class);

        return [
            [
                'category' => 'Admin accounts',
                'data' => 'Name, email address, role, second factor type, passkey names, last login time.',
                'stored' => 'Users table. Admins are deactivated, never deleted.',
                'retention' => 'Until the account is pseudonymised by an Owner.',
            ],
            [
                'category' => 'Sessions',
                'data' => 'IP address, browser description (user agent), last activity.',
                'stored' => 'Sessions table (database session driver).',
                'retention' => 'Until the session expires or ends, and the expired rows are deleted daily.',
            ],
            [
                'category' => 'Audit log',
                'data' => 'Who did what and when (by account number), IP address of the request. No names or email addresses.',
                'stored' => 'Audit log table, in a hash chain.',
                'retention' => $settings->auditLogRetentionDays().' days, then deleted daily with a checkpoint that keeps the chain verifiable.',
            ],
            [
                'category' => 'API access log',
                'data' => 'IP address of the server or pipeline that fetched credentials, token prefix, project, result. These addresses can be personal data when a person runs a pipeline from a home connection.',
                'stored' => 'Access log table.',
                'retention' => $settings->accessLogRetentionDays().' days, then deleted daily.',
            ],
            [
                'category' => 'Invitations',
                'data' => 'Email address of the invited person, the admin who invited them.',
                'stored' => 'Invitations table.',
                'retention' => $settings->invitationRetentionDays().' days after the invitation expired, was accepted or was revoked.',
            ],
            [
                'category' => 'Recent projects',
                'data' => 'Which projects an admin opened most recently, by account number and project, for the sidebar.',
                'stored' => 'User recent projects table.',
                'retention' => 'Until the project is deleted. The table is not cleared when an admin is pseudonymised: it holds only the account number.',
            ],
        ];
    }
}; ?>

<section class="w-full max-w-5xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Data processing') }}</flux:heading>
        <flux:text class="mt-2">
            {{ __('The personal data this vault processes. Use it as input for your own record of processing activities; it does not replace that record, and it is not legal advice.') }}
        </flux:text>
    </div>

    <div class="overflow-x-auto rounded-panel border border-border">
        <table class="w-full text-start text-sm">
            <thead class="bg-surface-sunken text-ink-muted">
                <tr>
                    <th class="px-4 py-2 text-start font-medium">{{ __('Category') }}</th>
                    <th class="px-4 py-2 text-start font-medium">{{ __('Data') }}</th>
                    <th class="px-4 py-2 text-start font-medium">{{ __('Stored in') }}</th>
                    <th class="px-4 py-2 text-start font-medium">{{ __('Kept for') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($this->categories() as $row)
                    <tr wire:key="category-{{ $loop->index }}">
                        <td class="px-4 py-2 font-medium">{{ __($row['category']) }}</td>
                        <td class="px-4 py-2">{{ __($row['data']) }}</td>
                        <td class="px-4 py-2">{{ __($row['stored']) }}</td>
                        <td class="px-4 py-2">{{ $row['retention'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>