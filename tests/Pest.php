<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

pest()->use(RefreshDatabase::class)->in('Feature');

pest()->beforeEach(function (): void {
    config(['vault.key' => vaultTestKey(), 'vault.key_version' => 1, 'vault.previous_keys' => '']);
})->in('Feature');

/**
 * Get a deterministic test key in the VAULT_KEY format. Different versions give different keys.
 */
function vaultTestKey(int $version = 1): string
{
    return 'base64:'.base64_encode(hash('sha256', "composer-vault-test-key-{$version}", true));
}
