<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ApplicationData;
use App\Models\Client;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

/**
 * Updates an OIDC application's name, redirect URIs, confidentiality, first-party
 * flag, PAR requirement and optional client_credentials grant.
 *
 * Side effects: may generate or clear the client secret when confidentiality changes.
 * Plain-text secret display belongs to the regenerate flow (sibling issue).
 */
final class UpdateApplicationAction
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
    ) {}

    public function execute(Client $application, ApplicationData $applicationData): Client
    {
        $this->clientRepository->update(
            $application,
            $applicationData->name,
            $applicationData->redirectUris,
        );

        $wasConfidential = $application->confidential();
        $attributes = [
            'is_first_party' => $applicationData->isFirstParty,
            'is_pushed_authorization_required' => $applicationData->isPushedAuthorizationRequired,
            'grant_types' => $applicationData->grantTypes(),
            'frontchannel_logout_uri' => $applicationData->frontchannelLogoutUri,
            'is_frontchannel_logout_session_required' => $applicationData->isFrontchannelLogoutSessionRequired,
        ];

        // A request that left the field out keeps the registered list; an empty list clears it.
        if ($applicationData->postLogoutRedirectUris !== null) {
            $attributes['post_logout_redirect_uris'] = $applicationData->postLogoutRedirectUris ?: null;
        }

        if ($applicationData->isConfidential && ! $wasConfidential) {
            $attributes['secret'] = Str::random(40);
        }

        if (! $applicationData->isConfidential && $wasConfidential) {
            $attributes['secret'] = null;
        }

        $application->forceFill($attributes)->save();

        return $application->refresh();
    }
}
