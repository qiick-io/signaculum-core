<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * LDAP transport encryption. TLS (LDAPS) or StartTLS is required by default (#77).
 */
enum LdapEncryptionEnum: string
{
    case Tls = 'tls';
    case StartTls = 'starttls';
    case None = 'none';

    public function usesTls(): bool
    {
        return $this === self::Tls;
    }

    public function usesStartTls(): bool
    {
        return $this === self::StartTls;
    }

    public function defaultPort(): int
    {
        return $this === self::Tls ? 636 : 389;
    }
}
