<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A freshly generated RSA key pair in PEM form, with its RFC 7638 thumbprint as `kid`.
 */
final readonly class SigningKeyMaterialData
{
    public function __construct(
        public string $kid,
        public string $publicKeyPem,
        #[\SensitiveParameter]
        public string $privateKeyPem,
    ) {}
}
