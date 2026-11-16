<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('documents every /api/v1 route in the openapi file', function (): void {
    $document = json_decode(
        (string) file_get_contents(resource_path('openapi/admin-v1.json')),
        true,
    );

    expect($document)->toBeArray()
        ->and($document['openapi'] ?? null)->toBe('3.1.0');

    $documentedPaths = array_keys($document['paths'] ?? []);

    $apiRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'api/v1/'))
        ->map(static function ($route): string {
            $suffix = substr($route->uri(), strlen('api/v1'));
            $path = str_starts_with($suffix, '/') ? $suffix : '/'.$suffix;

            if ($path === '/') {
                return '/';
            }

            $trimmed = rtrim($path, '/');

            return $trimmed === '' ? '/' : $trimmed;
        })
        ->unique()
        ->values();

    foreach ($apiRoutes as $path) {
        // openapi.json itself is listed; parameterized routes keep {param} names from routes/api.php
        $normalized = preg_replace('/\{[^}]+\}/', '{param}', $path) ?? $path;
        $matches = false;

        foreach ($documentedPaths as $documentedPath) {
            $documentedNormalized = preg_replace('/\{[^}]+\}/', '{param}', $documentedPath) ?? $documentedPath;

            if ($documentedNormalized === $normalized) {
                $matches = true;
                break;
            }
        }

        expect($matches)->toBeTrue("Missing OpenAPI path for {$path}");
    }
});
