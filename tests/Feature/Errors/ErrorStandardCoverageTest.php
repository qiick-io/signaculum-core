<?php

declare(strict_types=1);

use App\Enums\ApiErrorCodeEnum;

/**
 * All keys of one locale, from `lang/{locale}/*.php` (dot notation, file name as group) and
 * `lang/{locale}.json`. A missing key silently falls back to another locale, so the parity
 * test below compares key sets instead of relying on what a translated string looks like.
 *
 * @return list<string>
 */
function errorStandardTranslationKeys(string $locale): array
{
    $keys = [];
    $collect = static function (array $values, string $prefix) use (&$collect, &$keys): void {
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $collect($value, $path);

                continue;
            }

            $keys[] = $path;
        }
    };

    foreach (glob(lang_path($locale.'/*.php')) ?: [] as $file) {
        /** @var array<string, mixed> $values */
        $values = require $file;
        $collect($values, pathinfo($file, PATHINFO_FILENAME));
    }

    $jsonPath = lang_path($locale.'.json');

    if (is_file($jsonPath)) {
        /** @var array<string, mixed> $json */
        $json = json_decode((string) file_get_contents($jsonPath), true);
        $collect($json, '');
    }

    return array_values(array_unique($keys));
}

it('has every base translation key in italian and german', function (): void {
    $baseKeys = errorStandardTranslationKeys('en');

    expect($baseKeys)->not->toBeEmpty();

    foreach (['it', 'de'] as $locale) {
        $translatedKeys = errorStandardTranslationKeys($locale);

        expect(array_values(array_diff($baseKeys, $translatedKeys)))->toBe([], "Missing {$locale} keys");
    }
});

it('documents and translates every api error code', function (): void {
    $documentation = (string) file_get_contents(base_path('docs/api-errors.md'));

    expect($documentation)->toBeString();

    foreach (ApiErrorCodeEnum::cases() as $case) {
        expect($documentation)->toContain('`'.$case->value.'`');

        foreach (['en', 'it', 'de'] as $locale) {
            expect(__('api_errors.'.$case->value, [], $locale))->not->toBe('api_errors.'.$case->value);
        }
    }
});

/**
 * An explicit registry is used instead of grepping `throw new` call sites: the same exception
 * can be thrown by an Action shared by web, REST and CLI, and a static scan cannot resolve
 * aliases or rethrows. Adding any new App\Exceptions class must now fail this test until its
 * author chooses a code in the enum registry; framework exceptions are mapped by type there
 * too, with HttpException status codes handled by `forStatus()`.
 */
it('gives every application exception a registered api error code', function (): void {
    $registered = ApiErrorCodeEnum::applicationExceptionCodes();

    foreach (glob(app_path('Exceptions/*.php')) ?: [] as $file) {
        $exceptionClass = 'App\\Exceptions\\'.pathinfo($file, PATHINFO_FILENAME);

        expect(array_key_exists($exceptionClass, $registered))->toBeTrue("Unmapped {$exceptionClass}");
    }

    foreach ($registered as $exceptionClass => $code) {
        expect(class_exists($exceptionClass))->toBeTrue()
            ->and($code)->toBeInstanceOf(ApiErrorCodeEnum::class);
    }
});
