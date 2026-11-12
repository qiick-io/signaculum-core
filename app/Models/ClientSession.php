<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A relying-party session tied to one browser OIDC `sid` (Back-Channel Logout §2.4).
 *
 * @property int $user_id
 * @property string $client_id
 * @property string $oidc_session_id
 * @property Carbon|null $ended_at
 */
class ClientSession extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'client_id',
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

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }
}
