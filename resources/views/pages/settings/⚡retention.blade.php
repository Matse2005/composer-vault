<?php

use App\Models\AuditEntry;
use App\Vault\Settings\VaultSettings;
use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Retention')] class extends Component {
    public string $accessLogDays = '';

    public string $auditLogDays = '';

    public string $invitationDays = '';

    public function mount(VaultSettings $settings): void
    {
        $this->accessLogDays = (string) $settings->accessLogRetentionDays();
        $this->auditLogDays = (string) $settings->auditLogRetentionDays();
        $this->invitationDays = (string) $settings->invitationRetentionDays();
    }

    /**
     * The number of audit entries the next prune would delete with the value in the form.
     */
    public function auditEntriesAffected(): int
    {
        $days = (int) $this->auditLogDays;

        return $days < 1 ? 0 : AuditEntry::query()->where('created_at', '<', now()->subDays($days))->count();
    }

    public function save(VaultSettings $settings): void
    {
        $this->resetErrorBag();

        try {
            $settings->update([
                'retention.access_log_days' => $this->accessLogDays,
                'retention.audit_log_days' => $this->auditLogDays,
                'retention.invitation_days' => $this->invitationDays,
            ], auth()->user());
        } catch (ValidationException $exception) {
            $fields = ['retention.access_log_days' => 'accessLogDays', 'retention.audit_log_days' => 'auditLogDays', 'retention.invitation_days' => 'invitationDays'];

            foreach ($exception->errors() as $key => $messages) {
                $this->addError($fields[$key] ?? 'accessLogDays', $messages[0]);
            }

            return;
        }

        Flux::toast(__('Retention saved.'));
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Retention') }}</flux:heading>
        <flux:text class="mt-2">
            {{ __('How long each kind of data is kept. A nightly task deletes what is older. The minimums protect against losing evidence by accident; they are not legal requirements, and the right period is your decision.') }}
        </flux:text>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div>
            <flux:input wire:model.live.debounce.500ms="accessLogDays" type="number" min="30" :label="__('Access log in days')" />
            <flux:text class="mt-1 text-xs">{{ __('Access log rows older than :days days are deleted daily.', ['days' => (int) $accessLogDays]) }}</flux:text>
            <flux:error name="accessLogDays" />
        </div>

        <div>
            <flux:input wire:model.live.debounce.500ms="auditLogDays" type="number" min="90" :label="__('Audit log in days')" />
            <flux:text class="mt-1 text-xs" data-test="audit-consequence">
                {{ __('Audit entries older than :days days are deleted daily. The next run deletes :count entries. The hash chain stays verifiable from a checkpoint.', ['days' => (int) $auditLogDays, 'count' => $this->auditEntriesAffected()]) }}
            </flux:text>
            <flux:error name="auditLogDays" />
        </div>

        <div>
            <flux:input wire:model="invitationDays" type="number" min="1" :label="__('Invitations in days')" />
            <flux:text class="mt-1 text-xs">{{ __('Invitations are deleted this many days after they expired, were accepted or were revoked.') }}</flux:text>
            <flux:error name="invitationDays" />
        </div>

        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
    </form>
</section>