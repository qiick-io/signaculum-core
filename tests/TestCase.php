<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Fortify\Features;
use Laravel\Passport\Passport;

abstract class TestCase extends BaseTestCase
{
    /**
     * Passport signs access tokens with `storage/oauth-*.key`. They are git-ignored, so a
     * fresh checkout (and CI) generates them once on the first test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests render the Blade shell; they must not depend on a Vite build.
        $this->withoutVite();

        if (! file_exists(Passport::keyPath('oauth-private.key'))) {
            Artisan::call('passport:keys');
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
