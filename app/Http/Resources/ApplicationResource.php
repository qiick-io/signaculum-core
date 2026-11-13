<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Admin applications list / drawer row for an OIDC client.
 *
 * @mixin Client
 *
 * @property-read Client $resource
 */
final class ApplicationResource extends JsonResource
{
    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     redirect_uris: list<string>,
     *     is_confidential: bool,
     *     is_first_party: bool,
     *     created_at: string|null,
     *     updated_at: string|null,
     *     can_update: bool
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var Client $application */
        $application = $this->resource;

        return [
            'id' => $application->getKey(),
            'name' => $application->name,
            'redirect_uris' => $application->redirect_uris ?? [],
            'is_confidential' => $application->confidential(),
            'is_first_party' => (bool) $application->is_first_party,
            'created_at' => $application->created_at?->toIso8601String(),
            'updated_at' => $application->updated_at?->toIso8601String(),
            'can_update' => Gate::allows('update', $application),
        ];
    }
}
