<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\RotateApiKeyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateApiKeySecretRequest;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Rotates an API key's secret (shown once) with a new bounded expiry; the old secret keeps
 * working for the grace window.
 */
final class ApiKeySecretController extends Controller
{
    public function update(UpdateApiKeySecretRequest $request, ApiKey $apiKey, RotateApiKeyAction $rotateApiKeyAction): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $issuedApiKey = $rotateApiKeyAction->execute($apiKey, $user, $request->expiresAt());

        $request->session()->flash('plain_text_api_key', $issuedApiKey->plainTextToken);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('API key rotated.')]);

        return to_route('admin.api-keys.index');
    }
}
