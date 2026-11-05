<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RotateSigningKeyAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Create a new id_token signing key. Run it once at install time and then on a schedule
 * (for example every 90 days); retired keys stay in the JWKS for the overlap window.
 */
#[Signature('signaculum:rotate-signing-key')]
#[Description('Create a new id_token signing key and retire the current one')]
final class RotateSigningKeyCommand extends Command
{
    public function handle(RotateSigningKeyAction $rotateSigningKeyAction): int
    {
        $signingKey = $rotateSigningKeyAction->execute();

        $this->components->info("New signing key [{$signingKey->kid}] is active.");

        return self::SUCCESS;
    }
}
