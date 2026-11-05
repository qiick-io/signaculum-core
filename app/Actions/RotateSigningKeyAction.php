<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\SigningKey;
use App\Services\SigningKeyService;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Create a new id_token signing key and retire the previous active ones.
 *
 * The new key signs from now on. Retired keys stay in the JWKS for
 * `oidc.signing_key_overlap_hours`, so tokens signed just before the rotation still
 * verify; clients refetch the JWKS when they meet an unknown `kid`.
 */
final class RotateSigningKeyAction
{
    public function __construct(
        private readonly SigningKeyService $signingKeyService,
    ) {}

    public function execute(): SigningKey
    {
        $keyMaterial = $this->signingKeyService->generateKeyMaterial();

        return DB::transaction(function () use ($keyMaterial): SigningKey {
            SigningKey::query()->active()->update(['retired_at' => Date::now()]);

            return SigningKey::query()->create([
                'kid' => $keyMaterial->kid,
                'algorithm' => SigningKeyService::ALGORITHM,
                'public_key' => $keyMaterial->publicKeyPem,
                'private_key' => $keyMaterial->privateKeyPem,
            ]);
        });
    }
}
