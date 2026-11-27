<?php

use App\Actions\Vault\LinkCredentialToProjects;
use App\Actions\Vault\LinkProjectToCredentials;
use App\Actions\Vault\UpdateCredential;
use App\Exceptions\Vault\HostConflictException;
use App\Models\Credential;
use App\Models\Project;

it('replaces the project links of a credential', function () {
    $credential = Credential::factory()->create();
    [$first, $second, $third] = Project::factory()->count(3)->create();
    $credential->projects()->attach([$first->id, $second->id]);

    app(LinkCredentialToProjects::class)->handle($credential, [$second->id, $third->id]);

    expect($credential->projects()->pluck('projects.id')->sort()->values()->all())
        ->toBe(collect([$second->id, $third->id])->sort()->values()->all());
});

it('replaces the credential links of a project', function () {
    $project = Project::factory()->create();
    [$first, $second, $third] = Credential::factory()->count(3)->create();
    $project->credentials()->attach([$first->id, $second->id]);

    app(LinkProjectToCredentials::class)->handle($project, [$second->id, $third->id]);

    expect($project->credentials()->pluck('credentials.id')->sort()->values()->all())
        ->toBe(collect([$second->id, $third->id])->sort()->values()->all());
});

it('allows the same host in two different projects', function () {
    $credentialA = Credential::factory()->forHost('repo.magento.com')->create();
    $credentialB = Credential::factory()->forHost('repo.magento.com')->create();
    [$projectA, $projectB] = Project::factory()->count(2)->create();

    app(LinkCredentialToProjects::class)->handle($credentialA, [$projectA->id]);
    app(LinkCredentialToProjects::class)->handle($credentialB, [$projectB->id]);

    expect($projectA->credentials()->count())->toBe(1)
        ->and($projectB->credentials()->count())->toBe(1);
});

it('refuses two credentials with the same host in one project from the credential side', function () {
    $project = Project::factory()->create(['name' => 'Shop']);
    $existing = Credential::factory()->forHost('repo.magento.com')->create();
    $other = Credential::factory()->forHost('Repo.Magento.com')->create();
    $project->credentials()->attach($existing);

    try {
        app(LinkCredentialToProjects::class)->handle($other, [$project->id]);
        $this->fail('Expected a host conflict.');
    } catch (HostConflictException $exception) {
        expect($exception->projectName)->toBe('Shop')
            ->and($exception->host)->toBe('repo.magento.com');
    }

    expect($project->credentials()->pluck('credentials.id')->all())->toBe([$existing->id]);
});

it('refuses two credentials with the same host in one project from the project side', function () {
    $project = Project::factory()->create(['name' => 'Shop']);
    $keep = Credential::factory()->forHost('github.com')->create();
    $first = Credential::factory()->forHost('repo.magento.com')->create();
    $second = Credential::factory()->forHost('repo.magento.com')->create();
    $project->credentials()->attach($keep);

    expect(fn () => app(LinkProjectToCredentials::class)->handle($project, [$first->id, $second->id]))
        ->toThrow(HostConflictException::class);

    expect($project->credentials()->pluck('credentials.id')->all())->toBe([$keep->id]);
});

it('writes nothing when one of several projects conflicts', function () {
    $credential = Credential::factory()->forHost('repo.magento.com')->create();
    $free = Project::factory()->create();
    $taken = Project::factory()->create();
    $taken->credentials()->attach(Credential::factory()->forHost('repo.magento.com')->create());

    expect(fn () => app(LinkCredentialToProjects::class)->handle($credential, [$free->id, $taken->id]))
        ->toThrow(HostConflictException::class);

    expect($credential->projects()->count())->toBe(0);
});

it('allows re-saving the existing links of a credential', function () {
    $project = Project::factory()->create();
    $credential = Credential::factory()->forHost('repo.magento.com')->create();
    $project->credentials()->attach($credential);

    app(LinkCredentialToProjects::class)->handle($credential, [$project->id]);
    app(LinkProjectToCredentials::class)->handle($project, [$credential->id]);

    expect($project->credentials()->count())->toBe(1);
});

it('refuses a host change that conflicts with another credential in a linked project', function () {
    $project = Project::factory()->create();
    $project->credentials()->attach($existing = Credential::factory()->forHost('repo.magento.com')->create());
    $project->credentials()->attach($changing = Credential::factory()->forHost('github.com')->create());

    expect(fn () => app(UpdateCredential::class)->handle($changing, ['host' => 'https://repo.magento.com/']))
        ->toThrow(HostConflictException::class);

    expect($changing->refresh()->host)->toBe('github.com')
        ->and($existing->refresh()->host)->toBe('repo.magento.com');
});

it('updates a credential to a free host', function () {
    $project = Project::factory()->create();
    $credential = Credential::factory()->forHost('github.com')->create();
    $project->credentials()->attach($credential);

    app(UpdateCredential::class)->handle($credential, ['name' => 'Renamed', 'host' => 'https://GitLab.com/']);

    expect($credential->refresh()->name)->toBe('Renamed')
        ->and($credential->host)->toBe('gitlab.com');
});
