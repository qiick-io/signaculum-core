<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * The `id_token_hint` failed signature or issuer/audience checks
 * (OIDC RP-Initiated Logout 1.0 §2.1). Expiry alone is not a reason to throw.
 */
final class InvalidIdTokenHintException extends Exception {}
