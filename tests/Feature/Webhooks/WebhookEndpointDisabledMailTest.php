<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\WebhookEndpointNoticeRecipientsService;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Outbound\Enums\WebhookEndpointDisabledReasonEnum;
use Qiick\Outbound\Events\WebhookEndpointDisabledEvent;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Notifications\WebhookEndpointDisabledNotification;

/**
 * The mail about an endpoint the package disabled on its own names the endpoint's host, so it goes
 * only to people who may manage the instance settings today
 * ({@see WebhookEndpointNoticeRecipientsService}): the creator while still an active instance
 * admin, otherwise every active instance admin. Creating it once grants nothing.
 */
function disabledEndpointCreatedBy(?User $creator): WebhookEndpoint
{
    $endpoint = existingWebhook();
    $endpoint->forceFill(['created_by' => $creator?->getKey()])->save();

    return $endpoint;
}

function disableAutomatically(WebhookEndpoint $endpoint): void
{
    event(new WebhookEndpointDisabledEvent($endpoint->public_id, WebhookEndpointDisabledReasonEnum::AutoFailure, CarbonImmutable::now()));
}

beforeEach(function (): void {
    Notification::fake();
});

it('mails the creator while still an active instance admin, and no one else', function (): void {
    $creator = instanceAdmin();
    $otherAdmin = User::factory()->instanceAdmin()->create();

    disableAutomatically(disabledEndpointCreatedBy($creator));

    Notification::assertSentTo($creator, WebhookEndpointDisabledNotification::class);
    Notification::assertNotSentTo($otherAdmin, WebhookEndpointDisabledNotification::class);
});

it('mails the active instance admins instead of a creator who lost the role or was deactivated', function (User $formerAdmin): void {
    $activeAdmin = instanceAdmin();

    disableAutomatically(disabledEndpointCreatedBy($formerAdmin));

    Notification::assertNotSentTo($formerAdmin, WebhookEndpointDisabledNotification::class);
    Notification::assertSentTo($activeAdmin, WebhookEndpointDisabledNotification::class);
})->with([
    'demoted' => fn (): User => User::factory()->create(),
    'deactivated' => fn (): User => User::factory()->instanceAdmin()->inactive()->create(),
]);

it('mails the active instance admins when the creator is gone', function (): void {
    $activeAdmin = instanceAdmin();

    disableAutomatically(disabledEndpointCreatedBy(null));

    Notification::assertSentTo($activeAdmin, WebhookEndpointDisabledNotification::class);
});

it('mails nobody about a manual disable', function (): void {
    $activeAdmin = instanceAdmin();
    $endpoint = disabledEndpointCreatedBy($activeAdmin);

    event(new WebhookEndpointDisabledEvent($endpoint->public_id, WebhookEndpointDisabledReasonEnum::Manual, CarbonImmutable::now()));

    Notification::assertNothingSent();
});

// A long-running queue worker keeps the permission resolver between jobs: a demotion another
// process made must still count.
it('mails the active instance admins instead of a creator demoted after the worker saw them as admin', function (): void {
    $creator = instanceAdmin();
    $activeAdmin = User::factory()->instanceAdmin()->create();

    expect(app(EffectivePermissionResolver::class)->hasPermission($creator, 'can-manage-settings'))->toBeTrue();

    DB::table('model_has_roles')->where('model_id', $creator->getKey())->delete();
    disableAutomatically(disabledEndpointCreatedBy($creator));

    Notification::assertNotSentTo($creator, WebhookEndpointDisabledNotification::class);
    Notification::assertSentTo($activeAdmin, WebhookEndpointDisabledNotification::class);
});

// The mail is queued: a creator demoted between the queueing and the send gets nothing.
it('checks the recipient again when the queued mail is sent', function (): void {
    $channelManager = new ChannelManager(app());
    Notification::swap($channelManager);
    Queue::fake();
    $creator = instanceAdmin();
    disableAutomatically(disabledEndpointCreatedBy($creator));
    $queuedMail = Queue::pushed(SendQueuedNotifications::class)->sole();

    DB::table('model_has_roles')->where('model_id', $creator->getKey())->delete();
    $sentMails = 0;
    Event::listen(NotificationSent::class, function () use (&$sentMails): void {
        $sentMails++;
    });
    $queuedMail->handle($channelManager);

    expect($sentMails)->toBe(0);
});

it('links the mail to the configured application url, not the request host', function (): void {
    URL::useOrigin('https://attacker.example.net');
    $creator = instanceAdmin();

    disableAutomatically(disabledEndpointCreatedBy($creator));

    Notification::assertSentTo($creator, WebhookEndpointDisabledNotification::class, function (WebhookEndpointDisabledNotification $notification) use ($creator): bool {
        $actionUrl = (string) $notification->toMail($creator)->actionUrl;

        return ! str_contains($actionUrl, 'attacker.example.net') && str_ends_with($actionUrl, '/settings/instance/webhooks');
    });
});
