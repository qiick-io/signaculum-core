<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Invitation expiry
    |--------------------------------------------------------------------------
    |
    | How long a pending organisation invitation stays valid after create or
    | resend. The accept URL carries an opaque token; only its sha256 hash is
    | stored (see OrganizationInvitation).
    |
    */
    'invitation_expires_hours' => (int) env('ORGANIZATION_INVITATION_EXPIRES_HOURS', 72),

];
