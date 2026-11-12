<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Passport\Client as PassportClient;

/**
 * Passport OIDC client with Signaculum logout registration fields.
 *
 * UI name is "Application"; protocol name is "client". Custom columns live beside
 * Passport's schema because `oauth_clients` is upstream-owned.
 *
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
        'post_logout_redirect_uris' => 'array',
        'is_backchannel_logout_session_required' => 'bool',
    ];

    /**
     * @return HasMany<ClientSession, $this>
     */
    public function clientSessions(): HasMany
    {
        return $this->hasMany(ClientSession::class, 'client_id');
    }
}
