<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a principal was deactivated (`principals.deactivation_reason`, string(32)).
 *
 * The values follow the `qiick/laravel-access` principals design (§15.2), which Signaculum
 * adopts later: `offboarded` when the user leaves the organisation or the organisation is
 * deleted, `admin` when an administrator deactivates the user. A reactivation clears it.
 */
enum PrincipalDeactivationReasonEnum: string
{
    case Offboarded = 'offboarded';
    case Admin = 'admin';
}
