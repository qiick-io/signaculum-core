<?php

declare(strict_types=1);

/**
 * Second-factor change alert (en). Not an admin-editable template: the account owner must be
 * able to recognise it as a security alert whatever the instance customised (#168).
 */
return [

    'changed' => [
        'subject' => 'A sign-in factor of your :app account changed',
        'intro' => 'This change was just made to the second factors of your :app account (:email):',
        'advice' => 'If you made this change, no action is needed. If you did not, someone may know your password: change it now and check your second factors.',
        'action' => 'Review security settings',
    ],

    'changes' => [
        'two_factor_enabled' => 'Two-factor authentication setup was started: a new authenticator secret was created.',
        'two_factor_confirmed' => 'An authenticator app was confirmed as a second factor.',
        'two_factor_disabled' => 'Two-factor authentication with an authenticator app was turned off.',
        'recovery_codes_regenerated' => 'New recovery codes were generated. The previous codes no longer work.',
        'passkey_registered' => 'A passkey was added.',
        'passkey_deleted' => 'A passkey was removed.',
    ],

];
