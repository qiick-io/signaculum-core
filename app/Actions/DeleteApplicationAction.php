<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\WebhookEventEnum;
use App\Models\Client;
use App\Services\WebhookDispatcherService;
use Laravel\Passport\ClientRepository;

/**
 * Revokes an OIDC application and every token it issued (Passport ClientRepository).
 *
 * Side effects: marks the client revoked and revokes related access/refresh tokens;
 * queues `application.deleted` webhooks when endpoints are subscribed.
 */
final class DeleteApplicationAction
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly WebhookDispatcherService $webhookDispatcher,
    ) {}

    public function execute(Client $application): void
    {
        $payload = [
            'id' => $application->getKey(),
            'name' => $application->name,
        ];

        $this->clientRepository->delete($application);

        $this->webhookDispatcher->dispatch(WebhookEventEnum::ApplicationDeleted, $payload);
    }
}
