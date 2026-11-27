<?php

namespace App\Console\Commands;

use App\Actions\Vault\InviteAdmin;
use App\Enums\AuditAction;
use App\Exceptions\Vault\InvalidVaultKeyException;
use App\Exceptions\Vault\InvitationNotAllowedException;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Crypto\MasterKey;
use Composer\InstalledVersions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('vault:install {--name= : Name of the first admin (required with --no-interaction)} {--email= : Email address of the first admin (required with --no-interaction)}')]
#[Description('Prepare the keys and the database and create the first admin')]
class InstallVault extends Command
{
    /**
     * Run the installation. It refuses to run when an admin exists, and it never prints or stores a password in a
     * log: the interactive mode reads it hidden, the non-interactive mode creates an invitation instead.
     */
    public function handle(InviteAdmin $inviteAdmin, AuditLogger $auditLogger): int
    {
        if (Schema::hasTable('users') && User::query()->exists()) {
            $this->components->error('Composer Vault is already installed.');

            return self::FAILURE;
        }

        $interactive = $this->input->isInteractive();

        if (! $interactive && ! $this->hasValidAdminOptions()) {
            return self::FAILURE;
        }

        if (blank(config('app.key'))) {
            $this->call('key:generate', ['--force' => true]);
        }

        if (! $this->ensureVaultKey()) {
            return self::FAILURE;
        }

        $this->call('migrate', ['--force' => true]);

        if ($interactive) {
            $this->createAdmin($auditLogger);
        } elseif (! $this->inviteAdmin($inviteAdmin, $auditLogger)) {
            return self::FAILURE;
        }

        $this->printSummary();

        return self::SUCCESS;
    }

    /**
     * Check --name and --email, which the non-interactive mode needs because it cannot ask for them.
     */
    private function hasValidAdminOptions(): bool
    {
        $validator = Validator::make(
            ['name' => $this->option('name'), 'email' => $this->option('email')],
            ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'string', 'email', 'max:255']],
        );

        if ($validator->fails()) {
            $this->components->error('Without interaction, --name and --email are required: '.implode(' ', $validator->errors()->all()));

            return false;
        }

        return true;
    }

    /**
     * Make sure a valid VAULT_KEY is in place. A missing key is generated and written to the .env file. When that
     * is not possible, the lines are printed and the install stops before it changes the database.
     */
    private function ensureVaultKey(): bool
    {
        $configured = config('vault.key');

        if (filled($configured)) {
            try {
                MasterKey::fromEncoded(1, $configured, 'VAULT_KEY');
            } catch (InvalidVaultKeyException $exception) {
                $this->components->error($exception->getMessage());

                return false;
            }

            return true;
        }

        $key = MasterKey::PREFIX.base64_encode(random_bytes(MasterKey::KEY_BYTES));
        $path = $this->laravel->environmentFilePath();
        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($contents !== false && preg_match('/^VAULT_KEY=[ \t]*\S/m', $contents) === 1) {
            $this->components->error('VAULT_KEY is set in the .env file but is not loaded. Clear the configuration cache with "php artisan config:clear" and run the install again.');

            return false;
        }

        if ($contents === false || ! is_writable($path) || ! $this->writeEnvValues($path, $contents, ['VAULT_KEY' => $key, 'VAULT_KEY_VERSION' => '1'])) {
            $this->components->warn('The .env file cannot be written. Add these two lines to the environment of the site (for example in the Forge or VitoDeploy environment editor), then run the install again:');
            $this->newLine();
            $this->line('VAULT_KEY='.$key);
            $this->line('VAULT_KEY_VERSION=1');

            return false;
        }

        config(['vault.key' => $key, 'vault.key_version' => 1]);
        $this->components->info('VAULT_KEY was generated and written to the .env file.');

        return true;
    }

    /**
     * Set values in the .env contents: an existing (empty) line is replaced, a missing one is added.
     *
     * @param  array<string, string>  $values
     */
    private function writeEnvValues(string $path, string $contents, array $values): bool
    {
        foreach ($values as $name => $value) {
            $line = $name.'='.$value;

            $replaced = preg_replace_callback('/^'.$name.'=.*$/m', fn (): string => $line, $contents, 1, $count);

            $contents = $count > 0 && $replaced !== null
                ? $replaced
                : rtrim($contents, "\r\n")."\n".$line."\n";
        }

        return file_put_contents($path, $contents) !== false;
    }

    /**
     * Ask for the details of the first admin and create it. The email address is verified at once, because the
     * person at the console is the owner of the installation.
     */
    private function createAdmin(AuditLogger $auditLogger): void
    {
        $name = $this->option('name') ?: text('Name', required: true);
        $email = $this->option('email') ?: text('Email address', required: true, validate: fn (string $value): ?string => Validator::make(['email' => $value], ['email' => ['email', 'max:255']])->fails() ? 'Enter a valid email address.' : null);

        $plainPassword = password('Password', required: true, validate: fn (string $value): ?string => Validator::make(['password' => $value], ['password' => Password::defaults()])->errors()->first('password') ?: null);

        password('Confirm password', required: true, validate: fn (string $value): ?string => $value === $plainPassword ? null : 'The passwords do not match.');

        DB::transaction(function () use ($name, $email, $plainPassword, $auditLogger): void {
            $user = new User(['name' => $name, 'email' => mb_strtolower(trim($email)), 'password' => $plainPassword]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $auditLogger->record(AuditAction::VaultInstalled, null, ['version' => $this->appVersion(), 'admin_id' => $user->id]);
        });

        $this->components->info('The first admin was created.');
    }

    /**
     * Create an invitation for the first admin and print its one-time link. No password is ever involved.
     */
    private function inviteAdmin(InviteAdmin $inviteAdmin, AuditLogger $auditLogger): bool
    {
        try {
            $issued = $inviteAdmin->handle((string) $this->option('email'), null);
        } catch (InvitationNotAllowedException $exception) {
            $this->components->error($exception->getMessage());

            return false;
        }

        $auditLogger->record(AuditAction::VaultInstalled, null, ['version' => $this->appVersion(), 'invitation_id' => $issued->invitation->id]);

        $this->components->info('The first admin was invited. Open this link once to choose a password:');
        $this->line($issued->url);

        return true;
    }

    private function appVersion(): string
    {
        return InstalledVersions::getRootPackage()['pretty_version'];
    }

    private function printSummary(): void
    {
        $warning = 'Back up VAULT_KEY separately from your database. Without it, every stored credential is lost.';
        $border = '+'.str_repeat('-', strlen($warning) + 2).'+';

        $this->newLine();
        $this->line($border);
        $this->line('| '.$warning.' |');
        $this->line($border);
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Log in at '.config('app.url').'.');
        $this->line('  2. Set up two-factor authentication or a passkey.');
        $this->line('  3. Run "php artisan vault:doctor".');
        $this->line('  4. Add the scheduler: run "php artisan schedule:run" every minute.');
    }
}
