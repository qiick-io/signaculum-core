<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\WebhookEndpointNoticeRecipientsService;
use Illuminate\Notifications\Events\NotificationSending;
use Qiick\Outbound\Notifications\WebhookEndpointDisabledNotification;

/**
 * The "endpoint disabled" mail is queued: a recipient who lost the right to manage the settings
 * between the queueing and the send gets nothing ({@see WebhookEndpointNoticeRecipientsService}).
 * Returning false cancels that send (Laravel's NotificationSending).
 */
final class RecheckWebhookEndpointNoticeRecipientListener
{
    public function __construct(
        private readonly WebhookEndpointNoticeRecipientsService $webhookEndpointNoticeRecipientsService,
    ) {}

    public function handle(NotificationSending $event): bool
    {
        if (! $event->notification instanceof WebhookEndpointDisabledNotification) {
            return true;
        }

        $recipient = $event->notifiable instanceof User ? User::query()->find($event->notifiable->getKey()) : null;

        return $recipient instanceof User && $this->webhookEndpointNoticeRecipientsService->mayReceiveNotices($recipient);
    }
}
