<?php

use App\Vault\Settings\SettingsRegistry;
use App\Vault\Settings\VaultSettings;

it('lists every storable setting once, with a permission and an area', function () {
    $all = SettingsRegistry::all();

    expect(array_keys($all))->toEqual([...array_keys(VaultSettings::rules()), ...VaultSettings::SECRET_KEYS]);

    foreach ($all as $key => $definition) {
        expect($definition->key)->toBe($key)->and($definition->permission)->not->toBe('')->and($definition->area)->not->toBe('');
    }
});

it('marks secrets, critical settings and the owner-only setting', function () {
    expect(SettingsRegistry::get('sso.client_secret')->isSecret)->toBeTrue()
        ->and(SettingsRegistry::get('mail.password')->isSecret)->toBeTrue()
        ->and(SettingsRegistry::get('admin.allowed_ips')->isCritical)->toBeTrue()
        ->and(SettingsRegistry::get('retention.audit_log_days')->isCritical)->toBeTrue()
        ->and(SettingsRegistry::get('api.rate_limit_per_minute')->isCritical)->toBeFalse()
        ->and(SettingsRegistry::get('approval.enabled')->permission)->toBe(SettingsRegistry::OWNER_ONLY)
        ->and(SettingsRegistry::get('sso.enabled')->envVariable)->toBe('VAULT_SSO_ENABLED');
});

it('refuses to describe a setting that is not in the registry', function () {
    expect(SettingsRegistry::has('unknown.key'))->toBeFalse()
        ->and(fn () => SettingsRegistry::get('unknown.key'))->toThrow(InvalidArgumentException::class);
});

it('keeps every secret setting out of the plain value store of the rules', function () {
    foreach (VaultSettings::SECRET_KEYS as $key) {
        expect(array_key_exists($key, VaultSettings::rules()))->toBeFalse();
    }
});
