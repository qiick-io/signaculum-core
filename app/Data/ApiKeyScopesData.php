<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The scopes an API key holds, in the shared qiick vocabulary (app-ui docs/api-keys.md):
 * `<resource>:<action>`, the action `read` or `write`, and `write` implying `read` on the same
 * resource.
 *
 * It is built only from a value that fully parses, so a key whose stored scopes are missing,
 * empty, malformed or unknown to this product has no `ApiKeyScopesData` at all and is refused.
 * Nothing here knows this app's resources: the caller passes the scopes it recognises.
 */
final readonly class ApiKeyScopesData
{
    // `D`: `$` must not accept a trailing newline (`users:read\n`).
    public const string PATTERN = '/^[a-z][a-z0-9-]*:(read|write)$/D';

    /**
     * @param  non-empty-list<string>  $scopes
     */
    private function __construct(
        public array $scopes,
    ) {}

    /**
     * Parses a stored (or submitted) scope list, failing closed: anything other than a non-empty
     * list of known scopes in the vocabulary returns null.
     *
     * @param  list<string>  $knownScopes
     */
    public static function fromStored(mixed $storedScopes, array $knownScopes): ?self
    {
        if (! is_array($storedScopes) || $storedScopes === [] || ! array_is_list($storedScopes)) {
            return null;
        }

        foreach ($storedScopes as $storedScope) {
            if (! is_string($storedScope) || ! self::isWellFormed($storedScope) || ! in_array($storedScope, $knownScopes, true)) {
                return null;
            }
        }

        /** @var non-empty-list<string> $scopes */
        $scopes = self::highestPerResource($storedScopes);

        return new self($scopes);
    }

    /**
     * Keeps one scope per resource, the highest it holds: `users:write` replaces `users:read`.
     *
     * @param  list<string>  $scopes
     * @return list<string>
     */
    public static function highestPerResource(array $scopes): array
    {
        $uniqueScopes = array_values(array_unique($scopes));

        return array_values(array_filter(
            $uniqueScopes,
            static fn (string $scope): bool => ! (str_ends_with($scope, ':read')
                && in_array(self::resourceOf($scope).':write', $uniqueScopes, true)),
        ));
    }

    public static function isWellFormed(string $scope): bool
    {
        return preg_match(self::PATTERN, $scope) === 1;
    }

    /**
     * Whether the key may do what `$requiredScope` names. `write` satisfies `read` on the same
     * resource, never the other way round. A malformed requirement (a typo in a route) matches
     * nothing, so it refuses instead of allowing.
     */
    public function allows(string $requiredScope): bool
    {
        if (! self::isWellFormed($requiredScope)) {
            return false;
        }

        if (in_array($requiredScope, $this->scopes, true)) {
            return true;
        }

        return str_ends_with($requiredScope, ':read')
            && in_array(self::resourceOf($requiredScope).':write', $this->scopes, true);
    }

    private static function resourceOf(string $scope): string
    {
        return explode(':', $scope, 2)[0];
    }
}
