<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PrincipalTypeEnum;
use App\Models\Principal;
use Qiick\Access\Principals\PrincipalDirectory;

/**
 * The singleton `instance_service` principal that owns every Admin API key (decision 2026-11-22 /
 * #229), through qiick/laravel-access' {@see PrincipalDirectory::instance()}: the singleton index
 * makes it idempotent under concurrency. It is returned even when deactivated; the key check
 * looks at `deactivated_at`.
 */
final class EnsureInstanceServicePrincipalAction
{
    public function __construct(
        private readonly PrincipalDirectory $principalDirectory,
    ) {}

    public function execute(): Principal
    {
        $principal = $this->principalDirectory->instance(PrincipalTypeEnum::InstanceService->value, 'Instance Admin API');

        return Principal::query()->whereKey($principal->getKey())->firstOrFail();
    }
}
