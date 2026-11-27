<?php

namespace App\Models;

use App\Enums\AccessResult;
use Database\Factories\AccessLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $access_token_id
 * @property string|null $project_id
 * @property string|null $requested_slug
 * @property string $ip
 * @property string|null $user_agent
 * @property AccessResult $result
 * @property Carbon|null $created_at
 */
class AccessLog extends Model
{
    /** @use HasFactory<AccessLogFactory> */
    use HasFactory, MassPrunable;

    const UPDATED_AT = null;

    /**
     * Get the rows that are older than the retention period.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('vault.access_log_retention_days')));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => AccessResult::class,
        ];
    }
}
