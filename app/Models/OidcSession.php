<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Binding between a Laravel browser session and the OIDC `sid` claim.
 *
 * @property int $user_id
 * @property string $laravel_session_id
 * @property string $oidc_session_id
 * @property Carbon|null $ended_at
 */
class OidcSession extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'laravel_session_id',
        'oidc_session_id',
        'ended_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }
}
