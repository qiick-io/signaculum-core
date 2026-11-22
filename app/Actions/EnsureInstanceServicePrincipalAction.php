<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PrincipalTypeEnum;
use App\Models\Principal;
use Illuminate\Support\Facades\DB;

/**
 * Ensures the singleton `instance_service` principal that owns Admin REST API keys.
 *
 * Side effects: inserts the row on first use (organisation_id and user_id null).
 */
final class EnsureInstanceServicePrincipalAction
{
    public function execute(): Principal
    {
        $existing = Principal::query()
            ->where('type', PrincipalTypeEnum::InstanceService)
            ->first();

        if ($existing instanceof Principal) {
            return $existing;
        }

        return DB::transaction(function (): Principal {
            $locked = Principal::query()
                ->where('type', PrincipalTypeEnum::InstanceService)
                ->lockForUpdate()
                ->first();

            if ($locked instanceof Principal) {
                return $locked;
            }

            return Principal::query()->create([
                'type' => PrincipalTypeEnum::InstanceService,
                'user_id' => null,
                'organisation_id' => null,
                'name' => 'Instance Admin API',
            ]);
        });
    }
}
