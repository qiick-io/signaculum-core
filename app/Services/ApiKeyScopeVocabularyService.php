<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ApiKeyScopeEnum;
use Qiick\ApiKeys\Contracts\ScopeVocabulary;

/**
 * The Admin API scopes ({@see ApiKeyScopeEnum}) as qiick/laravel-api-keys' {@see ScopeVocabulary}:
 * a stored scope outside it makes the whole set unusable (fail closed).
 */
final class ApiKeyScopeVocabularyService implements ScopeVocabulary
{
    /**
     * @return list<string>
     */
    public function all(): array
    {
        return ApiKeyScopeEnum::values();
    }

    public function contains(string $value): bool
    {
        return ApiKeyScopeEnum::tryFrom($value) !== null;
    }
}
