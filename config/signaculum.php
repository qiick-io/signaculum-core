<?php

declare(strict_types=1);

/*
 * Product settings for Signaculum (the OIDC identity provider).
 * Env keys use the SIGNACULUM_ prefix so they stay distinct from Laravel's.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Impersonation TTL (minutes)
    |--------------------------------------------------------------------------
    |
    | How long an instance admin may stay signed in as another user before the
    | session restores the actor automatically. Zero disables the time box
    | (not recommended outside local debugging).
    |
    */
    'impersonation_ttl_minutes' => (int) env('SIGNACULUM_IMPERSONATION_TTL_MINUTES', 60),

];
