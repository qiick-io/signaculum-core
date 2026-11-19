<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the organisation id that should stamp an activity row.
 *
 * Prefer an explicit property, then the subject when it is an organisation or
 * belongs to one. Non-organisation actions stay null.
 */
final class ActivityOrganisationResolverService
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function resolve(?Model $subject, array $properties = []): ?string
    {
        $fromProperties = $properties['organisation_id'] ?? null;

        if (is_string($fromProperties) && $fromProperties !== '') {
            return $fromProperties;
        }

        return $this->fromSubject($subject);
    }

    private function fromSubject(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        if ($subject instanceof Organization) {
            return (string) $subject->getKey();
        }

        if ($subject instanceof OrganizationMembership || $subject instanceof OrganizationInvitation) {
            $organizationId = $subject->organization_id;

            return $organizationId !== '' ? $organizationId : null;
        }

        $organizationId = $subject->getAttribute('organization_id');

        if (is_string($organizationId) && $organizationId !== '') {
            return $organizationId;
        }

        return null;
    }
}
