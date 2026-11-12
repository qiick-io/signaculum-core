<?php

declare(strict_types=1);

namespace App\Http\Controllers\Oidc;

use App\Enums\OidcScopeEnum;
use App\Models\User;
use App\Services\OidcClaimsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET|POST /oauth/userinfo` (OIDC Core §5.3), behind the Passport `api` guard.
 *
 * The access token must carry `openid`; otherwise the request fails with
 * `insufficient_scope` (RFC 6750 §3.1).
 */
final class UserinfoController
{
    public function __invoke(Request $request, OidcClaimsService $oidcClaimsService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('api');

        if (! $user->tokenCan(OidcScopeEnum::OpenId->value)) {
            return response()
                ->json(['error' => 'insufficient_scope'], 403)
                ->header('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="openid"');
        }

        $grantedScopes = array_values(array_filter(
            array_map(fn (OidcScopeEnum $scope): string => $scope->value, OidcScopeEnum::cases()),
            fn (string $scope): bool => $user->tokenCan($scope),
        ));

        return response()
            ->json($oidcClaimsService->claimsFor($user, $grantedScopes))
            ->header('Cache-Control', 'no-store');
    }
}
