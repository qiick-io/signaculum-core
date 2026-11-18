<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An MFA requirement applies to the signed-in user, but this instance enables no
 * second-factor method (neither TOTP nor passkeys), so no enrolment or step-up can
 * meet it (#169). Rendered as the 403 error page with its own explanation; a redirect
 * to enrolment or login would only loop.
 */
final class SecondFactorUnavailableException extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'A second factor is required, but this instance has no second-factor method enabled.');
    }
}
