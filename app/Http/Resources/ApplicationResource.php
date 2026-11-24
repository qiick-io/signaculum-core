<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Admin applications list / drawer row for an OIDC client.
 *
 * @mixin Client
 *
 * @property-read Client $resource
 */
final class ApplicationResource extends JsonResource
{
    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     redirect_uris: list<string>,
     *     post_logout_redirect_uris: list<string>,
     *     is_confidential: bool,
     *     is_first_party: bool,
     *     is_client_credentials_enabled: bool,
     *     is_device_authorization_enabled: bool,
     *     is_pushed_authorization_required: bool,
     *     frontchannel_logout_uri: string|null,
     *     is_frontchannel_logout_session_required: bool,
     *     discovery_url: string,
     *     configure_command: string,
     *     created_at: string|null,
     *     updated_at: string|null,
     *     can_update: bool,
     *     can_delete: bool
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var Client $application */
        $application = $this->resource;
        $issuer = rtrim((string) config('oidc.issuer'), '/');
        $discoveryUrl = $issuer.'/.well-known/openid-configuration';
        $clientId = (string) $application->getKey();

        return [
            'id' => $clientId,
            'name' => $application->name,
            'redirect_uris' => $application->redirect_uris ?? [],
            'post_logout_redirect_uris' => $application->post_logout_redirect_uris ?? [],
            'is_confidential' => $application->confidential(),
            'is_first_party' => (bool) $application->is_first_party,
            'is_client_credentials_enabled' => $application->hasGrantType('client_credentials'),
            'is_device_authorization_enabled' => $application->hasGrantType('urn:ietf:params:oauth:grant-type:device_code'),
            'is_pushed_authorization_required' => $application->requiresPushedAuthorizationRequests(),
            'frontchannel_logout_uri' => $application->frontchannel_logout_uri,
            'is_frontchannel_logout_session_required' => (bool) $application->is_frontchannel_logout_session_required,
            'discovery_url' => $discoveryUrl,
            'configure_command' => sprintf(
                'php artisan oidc:configure %s --client-id=%s',
                $issuer,
                $clientId,
            ),
            'created_at' => $application->created_at?->toIso8601String(),
            'updated_at' => $application->updated_at?->toIso8601String(),
            'can_update' => Gate::allows('update', $application),
            'can_delete' => Gate::allows('delete', $application),
        ];
    }
}
