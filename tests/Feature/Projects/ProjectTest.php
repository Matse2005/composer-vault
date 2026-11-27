<?php

use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;

it('creates projects with ulid keys and unique slugs from the factory', function () {
    $projects = Project::factory()->count(3)->create();

    expect($projects->pluck('slug')->unique())->toHaveCount(3)
        ->and($projects->first()->getKey())->toBeString()->toHaveLength(26);
});

it('enforces slug uniqueness in the database', function () {
    Project::factory()->create(['slug' => 'shop']);

    Project::factory()->create(['slug' => 'shop']);
})->throws(UniqueConstraintViolationException::class);

it('only mass assigns name, slug and notes', function () {
    $project = Project::create([
        'id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
        'name' => 'Shop',
        'slug' => 'shop',
        'notes' => 'EU shop',
    ]);

    expect($project->getKey())->not->toBe('01HZZZZZZZZZZZZZZZZZZZZZZZ')
        ->and($project->refresh()->only(['name', 'slug', 'notes']))
        ->toBe(['name' => 'Shop', 'slug' => 'shop', 'notes' => 'EU shop']);
});
