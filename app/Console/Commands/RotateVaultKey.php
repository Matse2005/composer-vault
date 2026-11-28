<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Exceptions\Vault\DecryptionFailedException;
use App\Exceptions\Vault\InvalidVaultKeyException;
use App\Models\Credential;
use App\Models\VaultSetting;
use App\Vault\Audit\AuditLogger;
use App\Vault\Crypto\EncryptedSecret;
use App\Vault\Crypto\KeyProvider;
use App\Vault\Crypto\MasterKey;
use App\Vault\Crypto\RotatingKeyProvider;
use App\Vault\Crypto\SecretCipher;
use App\Vault\Settings\VaultSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

#[Signature('vault:rotate-key {--key= : Use this new key (base64:...) instead of generating one} {--force : Skip the confirmation in production}')]
#[Description('Re-encrypt the data key of every credential with a new master key')]
class RotateVaultKey extends Command
{
    use ConfirmableTrait;

    /**
     * Execute the console command.
     *
     * Every data key is re-encrypted in memory first. Rows are only written, in one transaction, when all of
     * them succeeded, so a corrupted row leaves the database untouched. Secret ciphertexts never change. The
     * rotation is audited in the same transaction.
     */
    public function handle(KeyProvider $keyProvider, AuditLogger $auditLogger): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        try {
            $current = $keyProvider->current();
            $newVersion = max($current->version, (int) Credential::query()->max('key_version')) + 1;
            $newKey = MasterKey::fromEncoded(
                $newVersion,
                $this->option('key') ?? 'base64:'.base64_encode(random_bytes(MasterKey::KEY_BYTES)),
                'The --key option',
            );
        } catch (InvalidVaultKeyException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $cipher = new SecretCipher(new RotatingKeyProvider($newKey, $keyProvider));
        $failedIds = [];
        $rotated = 0;

        DB::transaction(function () use ($cipher, $auditLogger, $current, $newKey, &$failedIds, &$rotated): void {
            $rewrapped = [];

            foreach (Credential::query()->lockForUpdate()->get() as $credential) {
                try {
                    $rewrapped[$credential->id] = $cipher->rewrap($credential->id, $credential->encryptedSecret());
                } catch (DecryptionFailedException) {
                    $failedIds[] = $credential->id;
                }
            }

            $rewrappedSettings = [];

            foreach (VaultSettings::SECRET_KEYS as $key) {
                $row = VaultSetting::query()->whereKey($key)->lockForUpdate()->first();

                if ($row === null || ! is_array($row->value)) {
                    continue;
                }

                try {
                    $new = $cipher->rewrap(VaultSettings::secretContext($key), new EncryptedSecret(
                        base64_decode((string) $row->value['secret']),
                        base64_decode((string) $row->value['data_key']),
                        (int) $row->value['key_version'],
                    ));
                    $rewrappedSettings[$key] = [...$row->value, 'data_key' => base64_encode($new->dataKeyCiphertext), 'key_version' => $new->keyVersion];
                } catch (DecryptionFailedException) {
                    $failedIds[] = 'setting:'.$key;
                }
            }

            if ($failedIds !== []) {
                return;
            }

            foreach ($rewrappedSettings as $key => $value) {
                VaultSetting::query()->whereKey($key)->update(['value' => $value]);
            }

            foreach ($rewrapped as $id => $encrypted) {
                DB::table('credentials')->where('id', $id)->update([
                    'data_key_ciphertext' => $encrypted->dataKeyCiphertext,
                    'key_version' => $encrypted->keyVersion,
                ]);
            }

            $rotated = count($rewrapped);

            $auditLogger->record(AuditAction::KeyRotated, null, [
                'old_version' => $current->version,
                'new_version' => $newKey->version,
                'credential_count' => $rotated,
            ]);
        });

        if ($failedIds !== []) {
            $this->components->error(count($failedIds).' credential(s) could not be decrypted with the known keys. Nothing was changed.');
            $this->line('Credential IDs: '.implode(', ', $failedIds));

            return self::FAILURE;
        }

        $this->printEnvChanges($current, $newKey, $rotated);

        return self::SUCCESS;
    }

    /**
     * Print the new key and the exact env changes, once.
     */
    private function printEnvChanges(MasterKey $current, MasterKey $newKey, int $rotated): void
    {
        $previousKeys = trim((string) config('vault.previous_keys'));
        $oldEntry = $current->version.':'.config('vault.key');

        $this->components->info("Re-encrypted {$rotated} credential(s) with key version {$newKey->version}.");
        $this->newLine();
        $this->line('Set these values in your environment now:');
        $this->newLine();
        $this->line('VAULT_KEY='.MasterKey::PREFIX.base64_encode($newKey->bytes));
        $this->line("VAULT_KEY_VERSION={$newKey->version}");
        $this->line('VAULT_PREVIOUS_KEYS='.($previousKeys === '' ? $oldEntry : "{$previousKeys},{$oldEntry}"));
        $this->newLine();
        $this->components->warn('Remove the old entry from VAULT_PREVIOUS_KEYS only after every credential is confirmed on the new version. vault:doctor will report when that is safe.');
    }
}
