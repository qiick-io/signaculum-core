<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin REST API OIDC application representation.
 *
 * @mixin Client
 */
final class ApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Client $application */
        $application = $this->resource;

        return [
            'id' => (string) $application->getKey(),
            'name' => $application->name,
            'redirect_uris' => $application->redirect_uris ?? [],
            'is_confidential' => $application->confidential(),
            'is_first_party' => (bool) $application->is_first_party,
            'is_client_credentials_enabled' => $application->hasGrantType('client_credentials'),
            'is_device_authorization_enabled' => $application->hasGrantType('urn:ietf:params:oauth:grant-type:device_code'),
            'is_pushed_authorization_required' => $application->requiresPushedAuthorizationRequests(),
            'frontchannel_logout_uri' => $application->frontchannel_logout_uri,
            'is_frontchannel_logout_session_required' => (bool) $application->is_frontchannel_logout_session_required,
            'created_at' => $application->created_at?->toIso8601String(),
            'updated_at' => $application->updated_at?->toIso8601String(),
        ];
    }
}
