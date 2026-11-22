<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\RotateApiKeyAction;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Rotates an API key's secret (shown once); the old secret keeps working for the grace window.
 */
final class ApiKeySecretController extends Controller
{
    public function update(Request $request, ApiKey $apiKey, RotateApiKeyAction $rotateApiKeyAction): RedirectResponse
    {
        Gate::authorize('update', $apiKey);

        /** @var User $user */
        $user = $request->user();

        $issuedApiKey = $rotateApiKeyAction->execute($apiKey, $user);

        $request->session()->flash('plain_text_api_key', $issuedApiKey->plainTextToken);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('API key rotated.')]);

        return to_route('admin.api-keys.index');
    }
}
