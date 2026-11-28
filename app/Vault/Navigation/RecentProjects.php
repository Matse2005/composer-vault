<?php

namespace App\Vault\Navigation;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The projects an admin opened most recently, for the sidebar. The list is per admin and holds no data about the
 * project beyond its ID.
 */
class RecentProjects
{
    public const LIMIT = 8;

    /**
     * Note that an admin opened a project.
     */
    public function record(User $user, Project $project): void
    {
        DB::table('user_recent_projects')->upsert(
            [['user_id' => $user->getKey(), 'project_id' => $project->getKey(), 'visited_at' => now()]],
            ['user_id', 'project_id'],
            ['visited_at'],
        );
    }

    /**
     * The projects the admin opened most recently, newest first.
     *
     * @return Collection<int, Project>
     */
    public function forUser(User $user): Collection
    {
        return Project::query()
            ->select(['projects.id', 'projects.name', 'projects.slug'])
            ->join('user_recent_projects', 'user_recent_projects.project_id', '=', 'projects.id')
            ->where('user_recent_projects.user_id', $user->getKey())
            ->orderByDesc('user_recent_projects.visited_at')
            ->orderByDesc('user_recent_projects.id')
            ->limit(self::LIMIT)
            ->get();
    }
}
