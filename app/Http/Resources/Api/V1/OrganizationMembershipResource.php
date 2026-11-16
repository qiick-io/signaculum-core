<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\OrganizationMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin REST API organisation membership representation.
 *
 * @mixin OrganizationMembership
 */
final class OrganizationMembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OrganizationMembership $membership */
        $membership = $this->resource;
        $user = $membership->user;

        return [
            'organization_id' => $membership->organization_id,
            'user_id' => $user?->subject,
            'email' => $user?->email,
            'roles' => $membership->roles,
            'created_at' => $membership->created_at?->toIso8601String(),
            'updated_at' => $membership->updated_at?->toIso8601String(),
        ];
    }
}
