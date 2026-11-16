<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Issues a new client secret for a confidential OIDC application.
 *
 * Side effects: overwrites `oauth_clients.secret` and writes a `secret_rotated` activity
 * entry (never the secret value). The plain secret is returned only via
 * {@see Client::$plainSecret} for a one-time flash.
 */
final class RegenerateApplicationSecretAction
{
    public function execute(Client $application, ?User $actor = null): Client
    {
        if (! $application->confidential()) {
            throw new InvalidArgumentException('Public clients have no secret to regenerate.');
        }

        $plainSecret = Str::random(40);

        $application->forceFill([
            'secret' => $plainSecret,
        ])->save();

        $application->plainSecret = $plainSecret;

        // Secret is excluded from model activity logging; record the rotation without the value.
        $activity = activity()
            ->performedOn($application)
            ->useLog('default')
            ->event('secret_rotated')
            ->withProperties([
                'client_id' => $application->getKey(),
            ]);

        if ($actor !== null) {
            $activity->causedBy($actor);
        }

        $activity->log('Application secret rotated');

        return $application;
    }
}
