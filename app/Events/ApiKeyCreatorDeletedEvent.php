<?php

declare(strict_types=1);

namespace App\Events;

use App\Actions\DeleteUserAction;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Domain event: deleting an account revoked the Admin API keys it created
 * ({@see DeleteUserAction}). Raised whenever the deletion revoked at least one key, whether or
 * not the user was still an instance admin, so the remaining admins always hear about it.
 */
final readonly class ApiKeyCreatorDeletedEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $revokedApiKeyIds
     */
    public function __construct(
        public User $creator,
        public array $revokedApiKeyIds,
    ) {}
}
