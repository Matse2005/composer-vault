<?php

namespace App\Vault\Search;

use App\Models\AccessToken;
use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use App\Vault\Authorization\Ability;

/**
 * Search over project names and slugs, credential names and hosts, and token names, grouped by the project a match
 * belongs to. Only the columns that are searched and shown are selected: no secret, ciphertext or token hash is read.
 * A result is only returned for a kind of record the admin may view.
 */
class GlobalSearch
{
    public const MIN_LENGTH = 2;

    public const LIMIT = 10;

    /**
     * @return list<array{project: array{id: string, name: string, slug: string}, matches: list<array{type: string, label: string, detail: string|null, url: string}>}>
     */
    public function search(User $user, string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return [];
        }

        $like = '%'.strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
        $groups = [];

        if ($user->can(Ability::ProjectsView)) {
            $projects = Project::query()
                ->select(['id', 'name', 'slug'])
                ->where(fn ($query) => $query->whereRaw("name like ? escape '!'", [$like])->orWhereRaw("slug like ? escape '!'", [$like]))
                ->orderBy('name')
                ->limit(self::LIMIT)
                ->get();

            foreach ($projects as $project) {
                $groups = $this->add($groups, $project, ['type' => 'project', 'label' => $project->name, 'detail' => $project->slug, 'url' => route('projects.show', $project)]);
            }
        }

        if ($user->can(Ability::CredentialsView)) {
            $credentials = Credential::query()
                ->select(['id', 'project_id', 'name', 'host'])
                ->with('project:id,name,slug')
                ->where(fn ($query) => $query->whereRaw("name like ? escape '!'", [$like])->orWhereRaw("host like ? escape '!'", [$like]))
                ->orderBy('name')
                ->limit(self::LIMIT)
                ->get();

            foreach ($credentials as $credential) {
                $groups = $this->add($groups, $credential->project, ['type' => 'credential', 'label' => $credential->name, 'detail' => $credential->host, 'url' => route('projects.show', $credential->project)]);
            }
        }

        if ($user->can(Ability::TokensView)) {
            $tokens = AccessToken::query()
                ->select(['id', 'project_id', 'name'])
                ->with('project:id,name,slug')
                ->whereRaw("name like ? escape '!'", [$like])
                ->orderBy('name')
                ->limit(self::LIMIT)
                ->get();

            foreach ($tokens as $token) {
                $groups = $this->add($groups, $token->project, ['type' => 'token', 'label' => $token->name, 'detail' => null, 'url' => route('projects.show', $token->project)]);
            }
        }

        return array_values($groups);
    }

    /**
     * @param  array<string, array{project: array{id: string, name: string, slug: string}, matches: list<array{type: string, label: string, detail: string|null, url: string}>}>  $groups
     * @param  array{type: string, label: string, detail: string|null, url: string}  $match
     * @return array<string, array{project: array{id: string, name: string, slug: string}, matches: list<array{type: string, label: string, detail: string|null, url: string}>}>
     */
    private function add(array $groups, Project $project, array $match): array
    {
        $group = $this->group($groups, $project);
        $group['matches'][] = $match;
        $groups[$project->id] = $group;

        return $groups;
    }

    /**
     * @param  array<string, array{project: array{id: string, name: string, slug: string}, matches: list<array{type: string, label: string, detail: string|null, url: string}>}>  $groups
     * @return array{project: array{id: string, name: string, slug: string}, matches: list<array{type: string, label: string, detail: string|null, url: string}>}
     */
    private function group(array $groups, Project $project): array
    {
        return $groups[$project->id] ?? ['project' => ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug], 'matches' => []];
    }
}
