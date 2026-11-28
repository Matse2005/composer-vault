<?php

namespace App\Vault\Settings;

use Throwable;

/**
 * Pushes the settings of the dashboard into the Laravel configuration that other code reads: the session lifetime and
 * the mail transport. It runs when the application boots, before the session middleware reads the lifetime and before
 * the mailer is resolved. It does nothing when the settings table does not exist yet (a fresh install, migrations).
 * With a cached configuration the cached values are the environment values and defaults, and the dashboard values are
 * applied here at runtime on top.
 */
class ApplyVaultSettings
{
    public function __construct(private VaultSettings $settings) {}

    public function handle(): void
    {
        try {
            $this->apply();
        } catch (Throwable) {
            // The settings table is missing or the key cannot decrypt a secret: keep the configuration as it is.
        }
    }

    private function apply(): void
    {
        config(['session.lifetime' => $this->settings->get('security.session_idle_minutes')]);

        if (config('mail.default') === 'smtp') {
            $this->applyMailTransport();
        }

        foreach (['address' => 'mail.from_address', 'name' => 'mail.from_name'] as $key => $setting) {
            $value = $this->settings->get($setting);

            if (filled($value)) {
                config(["mail.from.{$key}" => $value]);
            }
        }
    }

    /**
     * Only SMTP fields can be changed, and only while the transport is SMTP: the transport itself stays in the
     * environment, because other transports can run commands or write files.
     */
    private function applyMailTransport(): void
    {
        $values = [
            'host' => $this->settings->get('mail.host'),
            'port' => $this->settings->get('mail.port'),
            'username' => $this->settings->get('mail.username'),
            'password' => $this->settings->secret('mail.password'),
        ];

        foreach ($values as $key => $value) {
            if (filled($value)) {
                config(["mail.mailers.smtp.{$key}" => $value]);
            }
        }

        $encryption = $this->settings->get('mail.encryption');

        if (filled($encryption)) {
            config(['mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp']);
        }
    }
}
