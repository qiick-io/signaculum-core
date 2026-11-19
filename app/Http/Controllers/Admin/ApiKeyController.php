<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\CreateApiKeyAction;
use App\Actions\RevokeApiKeyAction;
use App\Enums\ApiKeyScopeEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreApiKeyRequest;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → API keys: list, create and revoke the signed-in admin's keys for the Admin REST API.
 * Session-authenticated only: no API key can reach key management.
 */
final class ApiKeyController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ApiKey::class);

        /** @var User $user */
        $user = $request->user();

        $apiKeys = ApiKey::query()
            ->where('owner_id', $user->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (ApiKey $apiKey): array => [
                'uuid' => $apiKey->uuid,
                'name' => $apiKey->name,
                'key_prefix' => $apiKey->key_prefix,
                'scopes' => $apiKey->scopes,
                'is_active' => $apiKey->isActive(),
                'expires_at' => $apiKey->expires_at?->toIso8601String(),
                'last_used_at' => $apiKey->last_used_at?->toIso8601String(),
                'last_used_ip' => $apiKey->last_used_ip,
                'previous_key_valid_until' => $apiKey->previous_key_valid_until?->toIso8601String(),
                'revoked_at' => $apiKey->revoked_at?->toIso8601String(),
                'created_at' => $apiKey->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/api-keys/index', [
            'apiKeys' => $apiKeys,
            'availableScopes' => ApiKeyScopeEnum::values(),
            'plainTextSecret' => $request->session()->pull('plain_text_api_key'),
        ]);
    }

    public function store(StoreApiKeyRequest $request, CreateApiKeyAction $createApiKeyAction): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $issuedApiKey = $createApiKeyAction->execute($user, $request->apiKeyData());

        $request->session()->flash('plain_text_api_key', $issuedApiKey->plainTextToken);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('API key created.')]);

        return to_route('admin.api-keys.index');
    }

    public function destroy(Request $request, ApiKey $apiKey, RevokeApiKeyAction $revokeApiKeyAction): RedirectResponse
    {
        Gate::authorize('delete', $apiKey);

        /** @var User $user */
        $user = $request->user();

        $revokeApiKeyAction->execute($apiKey, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API key revoked.')]);

        return to_route('admin.api-keys.index');
    }
}
