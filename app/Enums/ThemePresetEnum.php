<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Built-in appearance theme presets (config/theme-presets.php), named after Italian pigments
 * and stones. Ciclamino is Signaculum's own brand hue (348°) and the default; the others are
 * the generic presets. A sibling product's brand colour is never offered here, and the generic
 * Robbia preset is left out because it sits 5° from Ciclamino.
 */
enum ThemePresetEnum: string
{
    case Ciclamino = 'ciclamino';
    case Indaco = 'indaco';
    case Ametista = 'ametista';
    case Ardesia = 'ardesia';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Signaculum's brand preset (config/theme-presets.php `default`).
     */
    public static function default(): self
    {
        return self::Ciclamino;
    }

    public static function tryFromNullable(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::default();
        }

        return self::tryFrom($value) ?? self::default();
    }
}
