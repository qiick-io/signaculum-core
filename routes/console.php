<?php

declare(strict_types=1);

use App\Jobs\SyncLdapUsersJob;
use App\Models\EmailLoginCode;
use App\Models\PushedAuthorizationRequest;
use App\Services\SettingsRepositoryService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Prune activity log rows older than Settings → General → activity retention (days).
 * Reads the setting at run time so a mid-day change applies on the next clean.
 */
Schedule::call(fn () => Artisan::call('activitylog:clean', [
    '--days' => app(SettingsRepositoryService::class)->activityRetentionDays(),
    '--force' => true,
]))->name('activitylog:clean')->daily();

Schedule::command('model:prune', [
    '--model' => [EmailLoginCode::class, PushedAuthorizationRequest::class],
])->daily();

// qiick/laravel-api-keys: one notice per key and expiry, claimed under a lease (api-keys.expiry.*).
Schedule::command('api-keys:notify-expiring')->daily();

/*
 * LDAP read-only sync (#77). The job no-ops when disabled or when the configured
 * interval since last_synced_at has not elapsed.
 */
Schedule::job(new SyncLdapUsersJob)->everyMinute()->name('ldap:sync');
