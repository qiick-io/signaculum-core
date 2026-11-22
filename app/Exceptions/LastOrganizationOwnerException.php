<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an update or removal would leave an organisation with zero owners.
 */
final class LastOrganizationOwnerException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('An organisation must keep at least one owner.');
    }
}
