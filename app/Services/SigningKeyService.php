<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\RotateSigningKeyAction;
use App\Data\SigningKeyMaterialData;
use App\Models\SigningKey;
use Illuminate\Support\Facades\Date;
use RuntimeException;

/**
 * Reads the id_token signing keys: the active key, and the public JWKS (RFC 7517) that
 * clients use to verify signatures, including retired keys inside the overlap window.
 */
final class SigningKeyService
{
    public const string ALGORITHM = 'RS256';

    /**
     * The key that signs new id_tokens. On a fresh install there is none yet, so the
     * first call creates it.
     *
     * ponytail: lazy creation can race on the very first two concurrent logins and
     * leave two active keys; both are published, and the newest one signs. Run
     * `signaculum:rotate-signing-key` at install time to avoid it entirely.
     */
    public function activeKey(): SigningKey
    {
        return SigningKey::query()->active()->latest('id')->first()
            ?? app(RotateSigningKeyAction::class)->execute();
    }

    /**
     * The JSON Web Key Set served at `jwks_uri`.
     *
     * @return array{keys: list<array{kty: string, use: string, alg: string, kid: string, n: string, e: string}>}
     */
    public function publicKeySet(): array
    {
        $this->activeKey();

        $retiredAfter = Date::now()->subHours((int) config('oidc.signing_key_overlap_hours'));

        $publishedKeys = SigningKey::query()->published($retiredAfter)->latest('id')->get();

        return [
            'keys' => array_values($publishedKeys
                ->map(fn (SigningKey $signingKey): array => $this->publicJwk($signingKey->kid, $signingKey->public_key))
                ->all()),
        ];
    }

    /**
     * Generate a new RSA key pair. The `kid` is the RFC 7638 JWK thumbprint, so it is
     * derived from the key itself and never collides between keys.
     *
     * @throws RuntimeException when OpenSSL cannot generate or export the key
     */
    public function generateKeyMaterial(): SigningKeyMaterialData
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => (int) config('oidc.signing_key_bits'),
        ]);

        if ($privateKey === false || ! openssl_pkey_export($privateKey, $privateKeyPem)) {
            throw new RuntimeException('OpenSSL could not generate an RSA signing key.');
        }

        $keyDetails = openssl_pkey_get_details($privateKey);

        if ($keyDetails === false) {
            throw new RuntimeException('OpenSSL could not read the generated signing key.');
        }

        $publicKeyPem = (string) $keyDetails['key'];

        return new SigningKeyMaterialData(
            kid: $this->thumbprint($publicKeyPem),
            publicKeyPem: $publicKeyPem,
            privateKeyPem: (string) $privateKeyPem,
        );
    }

    /**
     * @return array{kty: string, use: string, alg: string, kid: string, n: string, e: string}
     */
    private function publicJwk(string $kid, string $publicKeyPem): array
    {
        $rsaComponents = $this->rsaComponents($publicKeyPem);

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => self::ALGORITHM,
            'kid' => $kid,
            'n' => $rsaComponents['n'],
            'e' => $rsaComponents['e'],
        ];
    }

    /**
     * RFC 7638 §3: SHA-256 over the required members in lexicographic order.
     */
    private function thumbprint(string $publicKeyPem): string
    {
        $rsaComponents = $this->rsaComponents($publicKeyPem);

        $canonicalJwk = sprintf('{"e":"%s","kty":"RSA","n":"%s"}', $rsaComponents['e'], $rsaComponents['n']);

        return $this->base64UrlEncode(hash('sha256', $canonicalJwk, true));
    }

    /**
     * Modulus and exponent of an RSA public key, base64url-encoded (RFC 7518 §6.3.1).
     *
     * @return array{n: string, e: string}
     *
     * @throws RuntimeException when the PEM is not an RSA public key
     */
    private function rsaComponents(string $publicKeyPem): array
    {
        $publicKey = openssl_pkey_get_public($publicKeyPem);
        $keyDetails = $publicKey === false ? false : openssl_pkey_get_details($publicKey);

        if ($keyDetails === false || ! isset($keyDetails['rsa']['n'], $keyDetails['rsa']['e'])) {
            throw new RuntimeException('Stored signing key is not a valid RSA public key.');
        }

        return [
            'n' => $this->base64UrlEncode((string) $keyDetails['rsa']['n']),
            'e' => $this->base64UrlEncode((string) $keyDetails['rsa']['e']),
        ];
    }

    private function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
