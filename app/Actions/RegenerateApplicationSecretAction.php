<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Client;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Issues a new client secret for a confidential OIDC application.
 *
 * Side effects: overwrites `oauth_clients.secret`. The plain secret is returned
 * only via {@see Client::$plainSecret} for a one-time flash.
 */
final class RegenerateApplicationSecretAction
{
    public function execute(Client $application): Client
    {
        if (! $application->confidential()) {
            throw new InvalidArgumentException('Public clients have no secret to regenerate.');
        }

        $application->forceFill([
            'secret' => Str::random(40),
        ])->save();

        return $application;
    }
}
