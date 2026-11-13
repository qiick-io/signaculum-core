<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ApplicationData;
use App\Models\Client;
use Laravel\Passport\ClientRepository;

/**
 * Registers an authorization-code OIDC application through Passport's ClientRepository.
 *
 * Side effects: creates an `oauth_clients` row (random secret when confidential).
 */
final class CreateApplicationAction
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
    ) {}

    public function execute(ApplicationData $applicationData): Client
    {
        /** @var Client $client */
        $client = $this->clientRepository->createAuthorizationCodeGrantClient(
            $applicationData->name,
            $applicationData->redirectUris,
            $applicationData->isConfidential,
        );

        $client->forceFill([
            'is_first_party' => $applicationData->isFirstParty,
        ])->save();

        return $client->refresh();
    }
}
