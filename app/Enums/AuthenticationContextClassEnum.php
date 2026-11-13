<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Signaculum authentication context class references for the OIDC `acr` claim.
 *
 * Emitted only when the client requested `acr` (`acr_values` or the `claims`
 * parameter). Values are qiick URNs agreed 2026-11-13 (OIDC Core §2 / §5.1).
 */
enum AuthenticationContextClassEnum: string
{
    case Password = 'urn:qiick:loa:pwd';
    case MultipleFactors = 'urn:qiick:loa:mfa';
}
