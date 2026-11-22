<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ApiKeyRotationData;
use App\Data\ApiKeySecretData;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Issues, parses, verifies and rotates API key secrets on plain values, with no model or
 * table behind it, so every qiick product can take the same rules.
 *
 * Token format (fleet-wide): `<prefix>_<environment>_<id><secret>`, for example
 * `sig_live_AbCd1234` followed by 32 more characters. The prefix and environment make a leaked
 * key easy to find (secret scanning); the 8-character id is public and, with them, is the
 * `key_prefix` the row is looked up by; the 32-character secret carries about 190 bits from the
 * CSPRNG. The row stores the SHA-256 of the FULL token (fleet rule), which binds the secret to
 * its own prefix, environment and id: an edited `live` / `test` or a swapped id fails. A fast
 * unsalted digest is right here: the token is high-entropy random, so a slow password hash
 * would only cost time on every request.
 */
final class ApiKeySecretService
{
    public const int ID_LENGTH = 8;

    public const int SECRET_LENGTH = 32;

    // `D`: `$` must not accept a trailing newline.
    private const string TOKEN_PATTERN = '/^([a-z]{3}_(?:live|test)_[A-Za-z0-9]{8})([A-Za-z0-9]{32,})$/D';

    /**
     * New key material with a new public id.
     */
    public function issue(): ApiKeySecretData
    {
        $keyPrefix = config('api.key_prefix', 'sig').'_'.config('api.key_environment', 'test').'_'.Str::random(self::ID_LENGTH);

        return $this->issueForPrefix($keyPrefix);
    }

    /**
     * The public `key_prefix` of a presented token, or null when it does not have the token shape
     * (the caller refuses it without a database lookup).
     */
    public function keyPrefixOf(string $plainTextToken): ?string
    {
        if (preg_match(self::TOKEN_PATTERN, $plainTextToken, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public function hash(string $plainTextToken): string
    {
        return hash('sha256', $plainTextToken);
    }

    /**
     * Whether `$plainTextToken` is the key's current token, or the one replaced by the last rotation
     * while its grace window is still open. Compared with `hash_equals()` so the comparison
     * cannot be timed.
     */
    public function matches(
        string $plainTextToken,
        string $keyHash,
        ?string $previousKeyHash,
        ?CarbonInterface $previousKeyValidUntil,
        CarbonInterface $now,
    ): bool {
        $presentedHash = $this->hash($plainTextToken);

        if (hash_equals($keyHash, $presentedHash)) {
            return true;
        }

        if ($previousKeyHash === null || $previousKeyValidUntil === null || ! $now->lessThan($previousKeyValidUntil)) {
            return false;
        }

        return hash_equals($previousKeyHash, $presentedHash);
    }

    /**
     * A new secret for the same key (same public `key_prefix`), with the current hash kept as the
     * previous one for the grace window. A second rotation inside the window ends the first
     * replaced secret at once: only the secret replaced by the last rotation stays valid.
     */
    public function rotate(string $keyPrefix, string $currentKeyHash, CarbonImmutable $now): ApiKeyRotationData
    {
        $graceHours = $this->rotationGraceHours();

        return new ApiKeyRotationData(
            secret: $this->issueForPrefix($keyPrefix),
            previousKeyHash: $graceHours > 0 ? $currentKeyHash : null,
            previousKeyValidUntil: $graceHours > 0 ? $now->addHours($graceHours) : null,
        );
    }

    /**
     * `api.key_rotation_grace_hours` as a whole number of hours. A value that is not a
     * non-negative integer counts as 0: a misconfiguration ends the old secret early, it never
     * keeps it alive longer.
     */
    public function rotationGraceHours(): int
    {
        $graceHours = filter_var(config('api.key_rotation_grace_hours'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return is_int($graceHours) ? $graceHours : 0;
    }

    private function issueForPrefix(string $keyPrefix): ApiKeySecretData
    {
        $plainTextToken = $keyPrefix.Str::random(self::SECRET_LENGTH);

        return new ApiKeySecretData(
            plainTextToken: $plainTextToken,
            keyPrefix: $keyPrefix,
            keyHash: $this->hash($plainTextToken),
        );
    }
}
