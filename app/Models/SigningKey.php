<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An RSA key pair that signs id_tokens (published in the JWKS by its `kid`).
 *
 * @property int $id
 * @property string $kid
 * @property string $algorithm
 * @property string $public_key
 * @property string $private_key
 * @property Carbon|null $retired_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['kid', 'algorithm', 'public_key', 'private_key', 'retired_at'])]
#[Hidden(['private_key'])]
class SigningKey extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'retired_at' => 'datetime',
        ];
    }

    /**
     * Keys that still sign new tokens (in practice one).
     *
     * @param  Builder<SigningKey>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('retired_at');
    }

    /**
     * Keys clients may still need to verify a token: active ones, and retired ones
     * inside the overlap window.
     *
     * @param  Builder<SigningKey>  $query
     */
    public function scopePublished(Builder $query, CarbonInterface $retiredAfter): void
    {
        $query->where(function (Builder $publishedQuery) use ($retiredAfter): void {
            $publishedQuery->whereNull('retired_at')->orWhere('retired_at', '>', $retiredAfter);
        });
    }
}
