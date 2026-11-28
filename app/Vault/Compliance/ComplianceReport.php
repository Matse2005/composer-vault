<?php

namespace App\Vault\Compliance;

use App\Enums\AdminRole;
use App\Enums\ControlStatus;
use App\Models\AccessReview;
use App\Models\Credential;
use App\Models\Incident;
use App\Models\User;
use App\Vault\Audit\AuditChainVerifier;
use App\Vault\Health\HealthRunner;
use App\Vault\Settings\VaultSettings;
use App\Vault\Sso\SsoConfig;
use Illuminate\Support\Carbon;

/**
 * The compliance report: one row per control with its current state, the evidence the vault can measure and the topic
 * it supports. The command and the page both use it, so they show the same controls. It holds counts, settings and
 * dates only, never a name, an email address or a secret.
 *
 * The vault supports a company in its own assessment. It does not make a company compliant, and no row says so. The
 * topics are named without article numbers, because the article texts were not checked by this software.
 */
class ComplianceReport
{
    public const DISCLAIMER = 'This report shows which controls Composer Vault provides and their current state. It is input for your own compliance assessment, not a certification.';

    public function __construct(
        private VaultSettings $settings,
        private SsoConfig $sso,
        private AuditChainVerifier $chain,
        private HealthRunner $health,
    ) {}

    /**
     * @return list<array{control: string, status: ControlStatus, evidence: string, supports: string}>
     */
    public function controls(): array
    {
        return [
            $this->multiFactor(),
            $this->singleSignOn(),
            $this->accessControl(),
            $this->encryption(),
            $this->logging(),
            $this->incidentHandling(),
            $this->retention(),
            $this->healthControl(),
        ];
    }

    /**
     * The report as plain data for the JSON output.
     *
     * @return array{disclaimer: string, version: string, controls: list<array{control: string, status: string, evidence: string, supports: string}>}
     */
    public function toArray(): array
    {
        return [
            'disclaimer' => self::DISCLAIMER,
            'version' => (string) config('vault.version'),
            'controls' => array_map(fn (array $row): array => [...$row, 'status' => $row['status']->value], $this->controls()),
        ];
    }

    /**
     * @return array{control: string, status: ControlStatus, evidence: string, supports: string}
     */
    private function multiFactor(): array
    {
        $active = User::query()->active()->get();
        $with = $active->filter(fn (User $admin): bool => $admin->hasSecondFactor())->count();
        $total = $active->count();
        $idpMfa = $this->sso->enforced() && $this->sso->trustIdpMfa();

        $status = match (true) {
            $total === 0 => ControlStatus::NotInPlace,
            $idpMfa || $with === $total => ControlStatus::InPlace,
            $with === 0 => ControlStatus::NotInPlace,
            default => ControlStatus::Partly,
        };

        return $this->row('Multi-factor authentication', $status, "{$with} of {$total} active admins have a second factor".($idpMfa ? '; sign-in through the identity provider is trusted for multi-factor' : '').'.', 'Authentication and access to systems');
    }

    /**
     * @return array{control: string, status: ControlStatus, evidence: string, supports: string}
     */
    private function singleSignOn(): array
    {
        $breakGlass = User::query()->active()->where('is_break_glass', true)->count();
        $enabled = $this->sso->isActive();
        $enforced = $this->sso->enforced();

        $status = match (true) {
            $enabled && $enforced && $breakGlass >= 1 && $breakGlass <= 2 => ControlStatus::InPlace,
            $enabled => ControlStatus::Partly,
            default => ControlStatus::NotInPlace,
        };

        $evidence = 'Single sign-on is '.($enabled ? 'on' : 'off').', '.($enforced ? 'enforced' : 'not enforced').", {$breakGlass} break-glass account(s).";

        return $this->row('Single sign-on and central deprovisioning', $status, $evidence, 'Access control and removal of access');
    }

    /**
     * @return array{control: string, status: ControlStatus, evidence: string, supports: string}
     */
    private function accessControl(): array
    {
        $roles = collect(AdminRole::cases())->map(fn (AdminRole $role): string => $role->value.': '.User::query()->active()->where('role', $role->value)->count())->implode(', ');
        $last = AccessReview::query()->max('completed_at');
        $interval = $this->settings->accessReviewIntervalDays();
        $recent = $last !== null && now()->subDays($interval)->lessThanOrEqualTo(Carbon::parse($last));
        $everyoneOwner = User::query()->active()->where('role', '!=', AdminRole::Owner->value)->doesntExist();

        $status = match (true) {
            $recent => ControlStatus::InPlace,
            $last !== null || ! $everyoneOwner => ControlStatus::Partly,
            default => ControlStatus::NotInPlace,
        };

        $date = $last === null ? 'never' : Carbon::parse($last)->format('Y-m-d');

        return $this->row('Access control and least privilege', $status, "Active admins per role ({$roles}). Last access review: {$date}, interval {$interval} days.", 'Access control and least privilege');
    }

    /**
     * @return array{control: string, status: ControlStatus, evidence: string, supports: string}
     */
    private function encryption(): array
    {
        $current = (int) config('vault.key_version');
        $total = Credential::query()->count();
        $outdated = Credential::query()->where('key_version', '!=', $current)->count();
        $hasKey = filled(config('vault.key'));

        $status = match (true) {
            ! $hasKey => ControlStatus::NotInPlace,
            $outdated === 0 => ControlStatus::InPlace,
            default => ControlStatus::Partly,
        };

        return $this->row('Encryption of secrets', $status, ($total - $outdated)." of {$total} credentials use the current key version ({$current}).", 'Cryptography and protection of personal data');
    }

    /**
     * @return array{control: string, status: ControlStatus, evidence: string, supports: string}
     */
    private function logging(): array
    {
        $broken = $this->chain->verify();
        $channel = $this->settings->securityLogChannel();

        $status = match (true) {
            $broken !== null => ControlStatus::NotInPlace,
            $channel !== null => ControlStatus::InPlace,
            default => ControlStatus::Partly,
        };

        $evidence = ($broken === null ? 'The audit log chain verifies.' : "The audit log chain is broken at entry {$broken}.").' Security event stream: '.($channel === null ? 'off' : 'on').'.';

        return $this->row('Logging and integrity', $status, $evidence, 'Logging, monitoring and integrity of logs');
    }

    /**
     * @return array{control: string, status: ControlStatus, evidence: string, supports: string}
     */
    private function incidentHandling(): array
    {
        $channel = app(SecurityAlerts::class)->hasChannel();
        $open = Incident::query()->whereNull('closed_at')->count();

        return $this->row(
            'Incident handling',
            $channel ? ControlStatus::InPlace : ControlStatus::NotInPlace,
            'Alert channel: '.($channel ? 'set' : 'not set').". Open incidents: {$open}.",
            'Detecting and handling incidents',
        );
    }

    /**
     * @return array{control: string, status: ControlStatus, evidence: string, supports: string}
     */
    private function retention(): array
    {
        return $this->row(
            'Retention',
            ControlStatus::InPlace,
            "Access log {$this->settings->accessLogRetentionDays()} days, audit log {$this->settings->auditLogRetentionDays()} days, invitations {$this->settings->invitationRetentionDays()} days.",
            'Storage limitation of personal data',
        );
    }

    /**
     * @return array{control: string, status: ControlStatus, evidence: string, supports: string}
     */
    private function healthControl(): array
    {
        $latest = $this->health->latest();

        if ($latest === null) {
            return $this->row('Health', ControlStatus::NotInPlace, 'No health check has run yet.', 'Operating the system securely');
        }

        $rows = collect($latest['rows']);
        $failed = $rows->whereIn('status', ['failed', 'crashed'])->count();
        $warnings = $rows->where('status', 'warning')->count();

        $status = match (true) {
            $failed > 0 => ControlStatus::NotInPlace,
            $warnings > 0 => ControlStatus::Partly,
            default => ControlStatus::InPlace,
        };

        return $this->row('Health', $status, "Latest run: {$rows->count()} checks, {$failed} failed, {$warnings} with a warning.", 'Operating the system securely');
    }

    /**
     * @return array{control: string, status: ControlStatus, evidence: string, supports: string}
     */
    private function row(string $control, ControlStatus $status, string $evidence, string $supports): array
    {
        return ['control' => $control, 'status' => $status, 'evidence' => $evidence, 'supports' => $supports];
    }
}
