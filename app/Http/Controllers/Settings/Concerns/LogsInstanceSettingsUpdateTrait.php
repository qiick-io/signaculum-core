<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings\Concerns;

use Illuminate\Http\Request;

/**
 * Shared activity-log writer for instance settings section updates (as in qiick-io/excubia-app).
 * Only the saved keys are logged, never their values.
 */
trait LogsInstanceSettingsUpdateTrait
{
    /**
     * @param  array<string, mixed>  $values  the values just saved
     */
    protected function logInstanceSettingsUpdate(Request $request, string $section, array $values): void
    {
        activity()
            ->causedBy($request->user())
            ->useLog('settings')
            ->event('settings_updated')
            ->withProperties([
                'section' => $section,
                'keys' => array_keys($values),
            ])
            ->log('Instance settings updated');
    }
}
