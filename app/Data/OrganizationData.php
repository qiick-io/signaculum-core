<?php

declare(strict_types=1);

namespace App\Data;

use App\Data\Concerns\GuardsCarriedPropertiesTrait;
use Illuminate\Support\Str;

/**
 * Validated payload for creating or updating an organisation.
 */
final readonly class OrganizationData
{
    use GuardsCarriedPropertiesTrait;

    /**
     * The promoted properties {@see self::fromValidated()} reads out of a validated payload.
     *
     * It checks this against the constructor by reflection, so a property added below
     * without an entry here throws instead of quietly taking its default (#186).
     *
     * @var list<string>
     */
    private const CARRIED_PROPERTIES = [
        'name',
        'slug',
        'isMfaRequired',
    ];

    public function __construct(
        public string $name,
        public string $slug,
        public bool $isMfaRequired = false,
    ) {}

    /**
     * Builds the payload from one validated admin request: the web forms or the Admin REST API.
     *
     * Organisations have the same two doors as applications, and built this object
     * field by field on each (#186). Both construct here now, so a field reaches one
     * only by reaching the other.
     *
     * An absent or empty slug is derived from the name, as the create form does.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        self::guardEveryPropertyIsCarried(
            self::CARRIED_PROPERTIES,
            'both organisation doors — the web form requests and the Admin REST API validation '
            .'— and is read back from the organisation resources',
        );

        $name = (string) ($validated['name'] ?? '');
        $slug = $validated['slug'] ?? null;

        return new self(
            name: $name,
            slug: is_string($slug) && $slug !== '' ? $slug : Str::slug($name),
            isMfaRequired: filter_var($validated['is_mfa_required'] ?? null, FILTER_VALIDATE_BOOLEAN),
        );
    }
}
