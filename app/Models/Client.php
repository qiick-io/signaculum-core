<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client as PassportClient;
use Laravel\Passport\Scope;

/**
 * OIDC application (Passport client) registered on this IdP.
 *
 * UI name is "Application"; protocol name is "client". First-party qiick apps set
 * {@see $is_first_party} so {@see skipsAuthorization()} skips consent.
 *
 * @property string $id
 * @property string $name
 * @property string|null $secret
 * @property list<string> $redirect_uris
 * @property list<string> $grant_types
 * @property bool $revoked
 * @property bool $is_first_party
 */
class Client extends PassportClient
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'grant_types' => 'array',
        'scopes' => 'array',
        'redirect_uris' => 'array',
        'personal_access_client' => 'bool',
        'password_client' => 'bool',
        'revoked' => 'bool',
        'is_first_party' => 'bool',
    ];

    /**
     * First-party qiick apps skip the consent screen (Passport extension point).
     *
     * @param  list<Scope>  $scopes
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return (bool) $this->is_first_party;
    }
}
