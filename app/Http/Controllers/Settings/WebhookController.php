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
use App\Services\SettingsRepositoryService;
use App\Services\WebhookEndpointUrlService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Qiick\Outbound\Models\WebhookEndpoint;

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
        $webhooks = WebhookEndpoint::query()
            ->with(['subscriptions', 'deliveries' => static fn ($query) => $query
                ->orderByDesc('created_at')
                ->orderByDesc('id')])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
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
        $issuedWebhook = $createWebhookAction->execute($request->toData());

        $request->session()->flash('plain_text_webhook_secret', $issuedWebhook->plainSecret);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Webhook created.')]);

        return to_route('instance.webhooks.index');
    }

    public function update(
        UpdateWebhookRequest $request,
        WebhookEndpoint $webhook,
        UpdateWebhookAction $updateWebhookAction,
    ): RedirectResponse {
        $updateWebhookAction->execute($webhook, $request->toData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Webhook updated.')]);

        return to_route('instance.webhooks.index');
    }

    public function destroy(
        DeleteWebhookRequest $request,
        WebhookEndpoint $webhook,
        DeleteWebhookAction $deleteWebhookAction,
    ): RedirectResponse {
        $deleteWebhookAction->execute($webhook);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Webhook deleted.')]);

        return to_route('instance.webhooks.index');
    }

    public function updatePrivateUrlsSetting(
        UpdateWebhookPrivateUrlsSettingRequest $request,
        WebhookEndpointUrlService $webhookEndpointUrlService,
    ): RedirectResponse {
        $values = $request->settings();
        $webhookEndpointUrlService->savePrivateHostsSetting($values['webhooks_allow_private_urls']);
        $this->logInstanceSettingsUpdate($request, 'webhooks', $values);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('instance.webhooks.index');
    }
}
