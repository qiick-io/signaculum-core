<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single-use RFC 9126 pushed authorization request referenced by `request_uri`.
 *
 * Bound to the pushing client; redeemed once at `/oauth/authorize`, then spent.
 *
 * @property string $id
 * @property string $request_uri
 * @property string $client_id
 * @property array<string, string> $parameters
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
class PushedAuthorizationRequest extends Model
{
    use HasUuids;

    protected $table = 'signaculum_pushed_authorization_requests';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'request_uri',
        'client_id',
        'parameters',
        'expires_at',
        'used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
