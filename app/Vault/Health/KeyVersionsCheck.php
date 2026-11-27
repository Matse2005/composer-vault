<?php

namespace App\Vault\Health;

use App\Exceptions\Vault\InvalidVaultKeyException;
use App\Exceptions\Vault\UnknownKeyVersionException;
use App\Models\Credential;
use App\Vault\Crypto\KeyProvider;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Fails when a credential uses a key version that no configured key resolves. Warns when VAULT_PREVIOUS_KEYS holds a
 * version that no credential uses any more, which is safe to remove.
 */
class KeyVersionsCheck extends Check
{
    public function __construct(private readonly KeyProvider $keyProvider)
    {
        parent::__construct();
    }

    public function run(): Result
    {
        try {
            $current = $this->keyProvider->current()->version;
        } catch (InvalidVaultKeyException) {
            return Result::make()->failed('The key versions cannot be checked because VAULT_KEY is not usable.')->shortSummary('VAULT_KEY is not usable');
        }

        /** @var array<int, int> $used */
        $used = Credential::query()->distinct()->pluck('key_version')->map(fn (mixed $version): int => (int) $version)->all();

        $unresolved = [];

        foreach ($used as $version) {
            try {
                $this->keyProvider->forVersion($version);
            } catch (UnknownKeyVersionException|InvalidVaultKeyException) {
                $unresolved[] = $version;
            }
        }

        if ($unresolved !== []) {
            $versions = implode(', ', $unresolved);

            return Result::make()
                ->failed("Credentials use key version {$versions}, but no configured key has that version.")
                ->shortSummary("Missing key version {$versions}")
                ->meta(['fix' => 'Add the missing key to VAULT_PREVIOUS_KEYS as <version>:<key>. Without it those credentials cannot be decrypted.']);
        }

        $unused = $this->unusedPreviousVersions($current, $used);

        if ($unused !== []) {
            $versions = implode(', ', $unused);

            return Result::make()
                ->warning("VAULT_PREVIOUS_KEYS holds version {$versions}, which no credential uses any more.")
                ->shortSummary("Previous key {$versions} is unused")
                ->meta(['fix' => "Remove version {$versions} from VAULT_PREVIOUS_KEYS."]);
        }

        return Result::make()->ok()->shortSummary('Every credential key version resolves');
    }

    /**
     * The versions in VAULT_PREVIOUS_KEYS that no credential uses. A broken entry is ignored here: forVersion()
     * reports it when a credential needs it.
     *
     * @param  array<int, int>  $used
     * @return array<int, int>
     */
    private function unusedPreviousVersions(int $current, array $used): array
    {
        $configured = trim((string) config('vault.previous_keys'));
        $unused = [];

        foreach ($configured === '' ? [] : explode(',', $configured) as $entry) {
            $version = (int) explode(':', trim($entry), 2)[0];

            if ($version > 0 && $version !== $current && ! in_array($version, $used, true)) {
                $unused[] = $version;
            }
        }

        return $unused;
    }
}
