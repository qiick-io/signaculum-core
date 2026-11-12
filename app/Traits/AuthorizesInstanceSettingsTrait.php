<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Setting;

/**
 * Shared authorization for the instance settings form requests (SettingPolicy: instance admins).
 */
trait AuthorizesInstanceSettingsTrait
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', Setting::class);
    }
}
