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
 * Opt-in `client_credentials` is written onto `grant_types` after Passport's create.
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

        // Passport hashes `secret` and keeps the plain value on `$client->plainSecret` only
        // until the model is refreshed; capture it before we touch the row again.
        $plainSecret = $client->plainSecret;

        $client->forceFill([
            'is_first_party' => $applicationData->isFirstParty,
            'grant_types' => $applicationData->grantTypes(),
        ])->save();

        $client->plainSecret = $plainSecret;

        return $client;
    }
}
