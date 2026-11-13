<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Client;
use Laravel\Passport\ClientRepository;

/**
 * Revokes an OIDC application and every token it issued (Passport ClientRepository).
 *
 * Side effects: marks the client revoked and revokes related access/refresh tokens.
 */
final class DeleteApplicationAction
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
    ) {}

    public function execute(Client $application): void
    {
        $this->clientRepository->delete($application);
    }
}
