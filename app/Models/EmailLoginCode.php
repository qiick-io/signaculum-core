<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * A one-time email sign-in code. Only the hash is stored; the plain code lives in the
 * queued notification until delivery. Expired and consumed rows are removed by
 * `model:prune` (scheduled daily).
 *
 * @property int $id
 * @property string $email
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['email', 'code_hash', 'expires_at', 'consumed_at'])]
class EmailLoginCode extends Model
{
    use MassPrunable;

    /**
     * Expired or already-consumed codes (no longer redeemable).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where(function (Builder $query): void {
                $query->whereNotNull('consumed_at')
                    ->orWhere('expires_at', '<', Date::now());
            });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
