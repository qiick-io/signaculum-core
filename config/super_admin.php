<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Initial super-admin (CreateSuperAdminSeeder)
    |--------------------------------------------------------------------------
    |
    | `db:seed` creates the first super-admin from these values when the email
    | is free. In `local` / `testing` any set password is accepted (including the
    | example `password`). Everywhere else `INITIAL_SUPER_ADMIN_PASSWORD` must be
    | set, must not be the known default `password`, and must pass the password
    | policy — otherwise the seeder skips. Servers can also create the first
    | admin at /setup.
    |
    */

    'first_name' => env('INITIAL_SUPER_ADMIN_FIRST_NAME', 'Super'),
    'last_name' => env('INITIAL_SUPER_ADMIN_LAST_NAME', 'Admin'),
    'email' => env('INITIAL_SUPER_ADMIN_EMAIL', 'superadmin@example.com'),
    'password' => env('INITIAL_SUPER_ADMIN_PASSWORD'),

];
