<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PrincipalTypeEnum;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Qiick\Access\Principals\Principal as PackagePrincipal;

/**
 * qiick/laravel-access' principal (`access.principals.model`) with Signaculum's relations: a
 * human membership (`user`), an organisation `service` account, or the singleton
 * `instance_service` Admin API owner (#229). Rows are written only through
 * `Qiick\Access\Principals\PrincipalDirectory`; `type` is the plain string the package compares
 * ({@see PrincipalTypeEnum} holds the values).
 */
class Principal extends PackagePrincipal
{
    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organisation_id');
    }

    /**
     * @return HasMany<ApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'owner_principal_id');
    }
}
