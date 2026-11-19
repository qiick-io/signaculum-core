<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidIdTokenHintException;
use App\Models\SigningKey;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Throwable;

/**
 * Validates `id_token_hint` for RP-initiated logout (OIDC RP-Initiated Logout 1.0 §2.1).
 *
 * Signature, `iss` and `aud` are required. Expiry is ignored so an RP can still end
 * the session after the id_token TTL.
 */
final class IdTokenHintValidatorService
{
    /**
     * @return array{subject: string, audience: list<string>, oidcSessionId: ?string, raw: UnencryptedToken}
     *
     * @throws InvalidIdTokenHintException
     */
    public function validate(string $idTokenHint, ?string $expectedClientId = null): array
    {
        if ($idTokenHint === '') {
            throw new InvalidIdTokenHintException('The id_token_hint is empty.');
        }

        try {
            $token = (new Parser(new JoseEncoder))->parse($idTokenHint);
        } catch (Throwable $exception) {
            throw new InvalidIdTokenHintException('The id_token_hint is not a JWT.', 0, $exception);
        }

        if (! $token instanceof UnencryptedToken) {
            throw new InvalidIdTokenHintException('The id_token_hint must be an unencrypted JWT.');
        }

        $kid = $token->headers()->get('kid');
        $signingKey = $this->signingKeyForKid(is_string($kid) ? $kid : null);
        $publicKey = $signingKey?->public_key;

        if ($signingKey === null || $publicKey === null || $publicKey === '' || ! (new Validator)->validate(
            $token,
            new SignedWith(new Sha256, InMemory::plainText($publicKey)),
        )) {
            throw new InvalidIdTokenHintException('The id_token_hint signature is invalid.');
        }

        $claims = $token->claims();
        $issuer = $claims->get('iss');
        $configuredIssuer = (string) config('oidc.issuer');

        if (! is_string($issuer) || ! hash_equals($configuredIssuer, $issuer)) {
            throw new InvalidIdTokenHintException('The id_token_hint issuer is invalid.');
        }

        $audiences = $this->audiences($claims->get('aud'));

        if ($audiences === []) {
            throw new InvalidIdTokenHintException('The id_token_hint audience is missing.');
        }

        if ($expectedClientId !== null && ! in_array($expectedClientId, $audiences, true)) {
            throw new InvalidIdTokenHintException('The id_token_hint audience does not match client_id.');
        }

        $subject = $claims->get('sub');

        if (! is_string($subject) || $subject === '') {
            throw new InvalidIdTokenHintException('The id_token_hint subject is missing.');
        }

        $oidcSessionId = $claims->get('sid');

        return [
            'subject' => $subject,
            'audience' => $audiences,
            'oidcSessionId' => is_string($oidcSessionId) && $oidcSessionId !== '' ? $oidcSessionId : null,
            'raw' => $token,
        ];
    }

    public function userForSubject(string $subject): ?User
    {
        // `sub` is stored as uuid; a forged hint with a non-UUID must not 500 on PostgreSQL (#197).
        if (! Str::isUuid($subject)) {
            return null;
        }

        return User::query()->where('subject', $subject)->first();
    }

    /**
     * Expiry is intentionally not checked for logout hints; this exists for call sites.
     */
    public function isExpired(UnencryptedToken $token): bool
    {
        $expiresAt = $token->claims()->get('exp');

        return $expiresAt instanceof DateTimeImmutable
            && $expiresAt < new DateTimeImmutable('@'.Date::now()->getTimestamp());
    }

    private function signingKeyForKid(?string $kid): ?SigningKey
    {
        $retiredAfter = Date::now()->subHours((int) config('oidc.signing_key_overlap_hours'));
        $query = SigningKey::query()->published($retiredAfter);

        if ($kid !== null) {
            $query->where('kid', $kid);
        }

        return $query->latest('id')->first();
    }

    /**
     * @return list<string>
     */
    private function audiences(mixed $audience): array
    {
        if (is_string($audience) && $audience !== '') {
            return [$audience];
        }

        if (! is_array($audience)) {
            return [];
        }

        return array_values(array_filter($audience, fn (mixed $value): bool => is_string($value) && $value !== ''));
    }
}
