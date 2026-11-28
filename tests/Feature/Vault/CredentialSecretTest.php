<?php

use App\Actions\Vault\CreateCredential;
use App\Actions\Vault\DeleteCredential;
use App\Actions\Vault\UpdateCredential;
use App\Enums\CredentialType;
use App\Exceptions\Vault\DecryptionFailedException;
use App\Exceptions\Vault\HostConflictException;
use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

const PLAINTEXT_MARKER = 'plaintext-secret-marker-9f3a';

function createCredentialWithSecret(string $secret = PLAINTEXT_MARKER, array $attributes = [], ?Project $project = null): Credential
{
    return app(CreateCredential::class)->handle($project ?? Project::factory()->create(), array_merge([
        'name' => 'Magento',
        'type' => CredentialType::HttpBasic,
        'host' => 'repo.magento.com',
        'username' => 'public-key',
    ], $attributes), $secret);
}

it('creates a credential whose secret can be revealed', function () {
    $project = Project::factory()->create();

    $credential = createCredentialWithSecret(project: $project);

    expect($credential->exists)->toBeTrue()
        ->and($credential->getKey())->toBeString()->toHaveLength(26)
        ->and($credential->key_version)->toBe(1)
        ->and($credential->secret_changed_at)->not->toBeNull()
        ->and($credential->revealSecret())->toBe(PLAINTEXT_MARKER)
        ->and(Credential::find($credential->getKey())->revealSecret())->toBe(PLAINTEXT_MARKER)
        ->and($credential->project_id)->toBe($project->id);
});

it('stores no plaintext in any column of the database', function () {
    createCredentialWithSecret();

    $row = (array) DB::table('credentials')->first();

    expect(json_encode($row))->not->toContain(PLAINTEXT_MARKER);

    foreach ($row as $value) {
        expect((string) $value)->not->toContain(PLAINTEXT_MARKER);
    }
});

it('creates nothing when the host rule is violated', function () {
    $project = Project::factory()->create();
    Credential::factory()->for($project)->forHost('repo.magento.com')->create();

    expect(fn () => createCredentialWithSecret(project: $project))->toThrow(HostConflictException::class);

    expect(Credential::query()->count())->toBe(1);
});

it('keeps plaintext and ciphertext out of arrays, json and dumps', function () {
    $credential = createCredentialWithSecret();
    $ciphertexts = [$credential->secret_ciphertext, $credential->data_key_ciphertext];

    ob_start();
    var_dump($credential);
    $varDump = ob_get_clean();

    $outputs = [
        json_encode($credential->toArray()),
        $credential->toJson(),
        json_encode($credential),
        $varDump,
        print_r($credential, true),
        json_encode(Credential::query()->get()),
    ];

    foreach ($outputs as $output) {
        expect($output)->not->toContain(PLAINTEXT_MARKER);

        foreach ($ciphertexts as $ciphertext) {
            expect($output)->not->toContain($ciphertext);
        }
    }
});

it('sets a secret on a new model without saving it', function () {
    $credential = new Credential;

    $credential->setSecret('new secret');

    expect($credential->exists)->toBeFalse()
        ->and($credential->getKey())->toBeString()->toHaveLength(26)
        ->and($credential->key_version)->toBe(1)
        ->and($credential->secret_changed_at)->not->toBeNull()
        ->and($credential->revealSecret())->toBe('new secret');
});

it('keeps the old secret when the new secret is null or empty', function (?string $newSecret) {
    $credential = createCredentialWithSecret('original secret');
    $before = $credential->only(['secret_ciphertext', 'data_key_ciphertext', 'secret_changed_at']);

    $this->travel(5)->minutes();
    app(UpdateCredential::class)->handle($credential, ['name' => 'Renamed'], $newSecret);

    $credential->refresh();

    expect($credential->name)->toBe('Renamed')
        ->and($credential->revealSecret())->toBe('original secret')
        ->and($credential->only(['secret_ciphertext', 'data_key_ciphertext', 'secret_changed_at']))->toEqual($before);
})->with([null, '']);

it('replaces the secret and its change date when a new secret is given', function () {
    $credential = createCredentialWithSecret('original secret');
    $changedAt = $credential->secret_changed_at;

    $this->travel(5)->minutes();
    app(UpdateCredential::class)->handle($credential, [], 'replacement secret');

    $credential->refresh();

    expect($credential->revealSecret())->toBe('replacement secret')
        ->and($credential->secret_changed_at->greaterThan($changedAt))->toBeTrue();
});

it('still checks the host rule when updating with a secret', function () {
    $project = Project::factory()->create();
    Credential::factory()->for($project)->forHost('repo.magento.com')->create();
    $credential = createCredentialWithSecret('original secret', ['host' => 'github.com'], $project);

    expect(fn () => app(UpdateCredential::class)->handle($credential, ['host' => 'repo.magento.com'], 'new secret'))
        ->toThrow(HostConflictException::class);

    expect($credential->refresh()->host)->toBe('github.com')
        ->and($credential->revealSecret())->toBe('original secret');
});

it('fails to reveal when the secret ciphertexts are swapped between two rows', function () {
    $first = createCredentialWithSecret('first secret', ['host' => 'one.example.com']);
    $second = createCredentialWithSecret('second secret', ['host' => 'two.example.com']);

    DB::table('credentials')->where('id', $first->id)->update(['secret_ciphertext' => $second->secret_ciphertext]);
    DB::table('credentials')->where('id', $second->id)->update(['secret_ciphertext' => $first->secret_ciphertext]);

    expect(fn () => $first->fresh()->revealSecret())->toThrow(DecryptionFailedException::class)
        ->and(fn () => $second->fresh()->revealSecret())->toThrow(DecryptionFailedException::class);
});

it('fails to reveal when the data keys are swapped between two rows', function () {
    $first = createCredentialWithSecret('first secret', ['host' => 'one.example.com']);
    $second = createCredentialWithSecret('second secret', ['host' => 'two.example.com']);

    DB::table('credentials')->where('id', $first->id)->update(['data_key_ciphertext' => $second->data_key_ciphertext]);
    DB::table('credentials')->where('id', $second->id)->update(['data_key_ciphertext' => $first->data_key_ciphertext]);

    expect(fn () => $first->fresh()->revealSecret())->toThrow(DecryptionFailedException::class)
        ->and(fn () => $second->fresh()->revealSecret())->toThrow(DecryptionFailedException::class);
});

it('deletes a credential and keeps its project', function () {
    $project = Project::factory()->create();
    $credential = createCredentialWithSecret(project: $project);

    app(DeleteCredential::class)->handle($credential);

    expect(Credential::query()->count())->toBe(0)
        ->and(Project::query()->count())->toBe(1);
});

it('builds factory credentials with a real encrypted secret', function () {
    $named = Credential::factory()->withSecret('chosen secret')->create();
    $random = Credential::factory()->create();
    $other = Credential::factory()->create();

    expect($named->revealSecret())->toBe('chosen secret')
        ->and($random->revealSecret())->not->toBe('')
        ->and($random->revealSecret())->not->toBe($other->revealSecret())
        ->and($random->secret_ciphertext)->not->toBe('placeholder-secret-ciphertext')
        ->and(Credential::factory()->make()->getAttributes())->not->toHaveKey('secret');
});
