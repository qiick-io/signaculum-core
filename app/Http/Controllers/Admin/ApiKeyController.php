<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\CreateApiKeyAction;
use App\Actions\RevokeApiKeyAction;
use App\Enums\ApiKeyScopeEnum;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → API keys: create and revoke keys for the Admin REST API.
 */
final class ApiKeyController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->is_instance_admin, 403);

        $apiKeys = ApiKey::query()
            ->where('owner_id', $user->getKey())
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (ApiKey $apiKey): array => [
                'uuid' => $apiKey->uuid,
                'name' => $apiKey->name,
                'key_prefix' => $apiKey->key_prefix,
                'scopes' => $apiKey->scopes,
                'is_active' => $apiKey->isActive(),
                'expires_at' => $apiKey->expires_at?->toIso8601String(),
                'last_used_at' => $apiKey->last_used_at?->toIso8601String(),
                'revoked_at' => $apiKey->revoked_at?->toIso8601String(),
                'created_at' => $apiKey->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/api-keys/index', [
            'apiKeys' => $apiKeys,
            'availableScopes' => ApiKeyScopeEnum::values(),
            'plainTextSecret' => $request->session()->pull('plain_text_api_key'),
        ]);
    }

    public function store(Request $request, CreateApiKeyAction $createApiKeyAction): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->is_instance_admin, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', Rule::in(ApiKeyScopeEnum::values())],
        ]);

        /** @var list<string> $scopes */
        $scopes = array_values(array_unique($validated['scopes']));

        $apiKey = $createApiKeyAction->execute($user, $validated['name'], $scopes);

        $plainSecret = $apiKey->getAttribute('plain_secret');

        if (is_string($plainSecret)) {
            $request->session()->flash('plain_text_api_key', $plainSecret);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API key created.')]);

        return to_route('admin.api-keys.index');
    }

    public function destroy(Request $request, ApiKey $apiKey, RevokeApiKeyAction $revokeApiKeyAction): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->is_instance_admin, 403);
        abort_unless($apiKey->owner_id === $user->getKey(), 403);

        $revokeApiKeyAction->execute($apiKey);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API key revoked.')]);

        return to_route('admin.api-keys.index');
    }
}
