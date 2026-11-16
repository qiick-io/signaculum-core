<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\CreateWebhookAction;
use App\Actions\DeleteWebhookAction;
use App\Actions\UpdateWebhookAction;
use App\Enums\WebhookEventEnum;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\LogsInstanceSettingsUpdateTrait;
use App\Http\Requests\Settings\DeleteWebhookRequest;
use App\Http\Requests\Settings\StoreWebhookRequest;
use App\Http\Requests\Settings\UpdateWebhookPrivateUrlsSettingRequest;
use App\Http\Requests\Settings\UpdateWebhookRequest;
use App\Http\Resources\WebhookResource;
use App\Models\Webhook;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Webhooks: instance-admin CRUD for outbound event endpoints.
 */
final class WebhookController extends Controller
{
    use LogsInstanceSettingsUpdateTrait;

    public function __construct(
        private readonly SettingsRepositoryService $settings,
    ) {}

    public function index(): Response
    {
        $webhooks = Webhook::query()
            ->with(['deliveries' => static fn ($query) => $query->latest()])
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('settings/instance/webhooks', [
            'webhooks' => WebhookResource::collection($webhooks),
            'availableEvents' => WebhookEventEnum::values(),
            'settings' => $this->settings->forEditOnly(['webhooks_allow_private_urls']),
            'plainTextSecret' => request()->session()->pull('plain_text_webhook_secret'),
        ]);
    }

    public function store(StoreWebhookRequest $request, CreateWebhookAction $createWebhookAction): RedirectResponse
    {
        $webhook = $createWebhookAction->execute($request->toData());

        $plainSecret = $webhook->getAttribute('plain_secret');

        if (is_string($plainSecret)) {
            $request->session()->flash('plain_text_webhook_secret', $plainSecret);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Webhook created.')]);

        return to_route('instance.webhooks.index');
    }

    public function update(
        UpdateWebhookRequest $request,
        Webhook $webhook,
        UpdateWebhookAction $updateWebhookAction,
    ): RedirectResponse {
        $updateWebhookAction->execute($webhook, $request->toData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Webhook updated.')]);

        return to_route('instance.webhooks.index');
    }

    public function destroy(
        DeleteWebhookRequest $request,
        Webhook $webhook,
        DeleteWebhookAction $deleteWebhookAction,
    ): RedirectResponse {
        $deleteWebhookAction->execute($webhook);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Webhook deleted.')]);

        return to_route('instance.webhooks.index');
    }

    public function updatePrivateUrlsSetting(
        UpdateWebhookPrivateUrlsSettingRequest $request,
    ): RedirectResponse {
        $values = $request->settings();
        $this->settings->save($values);
        $this->logInstanceSettingsUpdate($request, 'webhooks', $values);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('instance.webhooks.index');
    }
}
