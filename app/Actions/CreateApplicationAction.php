<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ApplicationData;
use App\Enums\WebhookEventEnum;
use App\Models\Client;
use App\Services\WebhookDispatcherService;
use Laravel\Passport\ClientRepository;

/**
 * Registers an authorization-code OIDC application through Passport's ClientRepository.
 *
 * Side effects: creates an `oauth_clients` row (random secret when confidential);
 * queues `application.created` webhooks when endpoints are subscribed.
 * Opt-in `client_credentials` is written onto `grant_types` after Passport's create.
 */
final class CreateApplicationAction
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly WebhookDispatcherService $webhookDispatcher,
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
            'is_pushed_authorization_required' => $applicationData->isPushedAuthorizationRequired,
            'grant_types' => $applicationData->grantTypes(),
            'post_logout_redirect_uris' => $applicationData->postLogoutRedirectUris ?: null,
            'frontchannel_logout_uri' => $applicationData->frontchannelLogoutUri,
            'is_frontchannel_logout_session_required' => $applicationData->isFrontchannelLogoutSessionRequired,
        ])->save();

        $client->plainSecret = $plainSecret;

        $this->webhookDispatcher->dispatch(WebhookEventEnum::ApplicationCreated, [
            'id' => $client->getKey(),
            'name' => $client->name,
        ]);

        return $client;
    }
}
