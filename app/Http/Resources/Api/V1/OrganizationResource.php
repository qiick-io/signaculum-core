<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin REST API organisation representation.
 *
 * @mixin Organization
 */
final class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Organization $organization */
        $organization = $this->resource;

        return [
            'id' => $organization->getKey(),
            'name' => $organization->name,
            'slug' => $organization->slug,
            'created_at' => $organization->created_at?->toIso8601String(),
            'updated_at' => $organization->updated_at?->toIso8601String(),
        ];
    }
}
