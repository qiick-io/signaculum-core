<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Passport\Client as PassportClient;
use Laravel\Passport\Scope;

/**
 * OIDC application (Passport client) registered on this IdP.
 *
 * UI name is "Application"; protocol name is "client". First-party qiick apps set
 * {@see $is_first_party} so {@see skipsAuthorization()} skips consent. Logout URI
 * columns live beside Passport's schema because `oauth_clients` is upstream-owned.
 *
 * @property string $id
 * @property string $name
 * @property string|null $secret
 * @property list<string> $redirect_uris
 * @property list<string> $grant_types
 * @property bool $revoked
 * @property bool $is_first_party
 * @property list<string> $post_logout_redirect_uris
 * @property string|null $backchannel_logout_uri
 * @property bool $is_backchannel_logout_session_required
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
        'post_logout_redirect_uris' => 'array',
        'is_backchannel_logout_session_required' => 'bool',
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

    /**
     * @return HasMany<ClientSession, $this>
     */
    public function clientSessions(): HasMany
    {
        return $this->hasMany(ClientSession::class, 'client_id');
    }
}
