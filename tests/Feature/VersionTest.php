<?php

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

it('reads a semantic version from the VERSION file', function () {
    $version = trim((string) file_get_contents(base_path('VERSION')));

    expect($version)->toMatch('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/')
        ->and(config('vault.version'))->toBe($version);
});

it('keeps the changelog in step with the version', function () {
    expect(file_get_contents(base_path('CHANGELOG.md')))->toContain('## [Unreleased]')->toContain('## ['.config('vault.version').']');
});

it('shows the version in the sidebar', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());

    $this->get(route('dashboard'))->assertOk()->assertSee('Composer Vault '.config('vault.version'));
});

it('prints the version in the doctor output', function () {
    Artisan::call('vault:doctor');

    expect(Artisan::output())->toContain('Composer Vault '.config('vault.version'));
});

it('prints and audits the version when installing', function () {
    $this->artisan('vault:install', ['--no-interaction' => true, '--name' => 'First Admin', '--email' => 'first@example.test'])
        ->expectsOutputToContain('Composer Vault '.config('vault.version').' is installed.')
        ->assertSuccessful();

    expect(AuditEntry::query()->where('event', 'vault_installed')->sole()->properties['version'])->toBe(config('vault.version'));
});
