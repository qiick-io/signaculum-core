<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\User;

/**
 * Everything needed to mint one id_token for a token response.
 */
final readonly class IdTokenRequestData
{
    /**
     * @param  list<string>  $scopes  granted scopes of the access token
     */
    public function __construct(
        public User $user,
        public string $clientId,
        public array $scopes,
        public AuthorizationContextData $context,
    ) {}
}
