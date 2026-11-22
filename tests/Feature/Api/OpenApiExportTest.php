<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('documents every /api/v1 route in the openapi file', function (): void {
    $document = json_decode(
        (string) file_get_contents(resource_path('openapi/admin-v1.json')),
        true,
    );

    expect($document)->toBeArray()
        ->and($document['openapi'] ?? null)->toBe('3.1.0')
        ->and($document['servers'][0]['url'] ?? null)->toBe('/');

    $documentedPaths = array_keys($document['paths'] ?? []);

    $apiRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'api/v1/'))
        ->map(static fn ($route): string => '/'.$route->uri())
        ->unique()
        ->values();

    foreach ($apiRoutes as $path) {
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
