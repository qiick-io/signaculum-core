<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PermissionEnum;
use App\Models\User;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Outbound\Contracts\WebhookEndpointNoticeRecipientsContract;
use Qiick\Outbound\Models\WebhookEndpoint;

/**
 * Who gets qiick/laravel-outbound's "endpoint disabled" mail. It names the endpoint's host, so it
 * goes only to who may manage the instance settings today: the creator while still an active
 * user with `can-manage-settings`, otherwise every such user. A demoted, deactivated or deleted
 * creator neither learns the host nor leaves the admins uninformed.
 */
final class WebhookEndpointNoticeRecipientsService implements WebhookEndpointNoticeRecipientsContract
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissionResolver,
    ) {}

    /**
     * @return iterable<User>
     */
    public function recipientsFor(WebhookEndpoint $endpoint): iterable
    {
        $creator = User::query()->find($endpoint->created_by);

        if ($creator instanceof User && $this->mayReceiveNotices($creator)) {
            return [$creator];
        }

        return User::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $this->mayReceiveNotices($user))
            ->values()
            ->all();
    }

    /**
     * Decided afresh: the permission resolver is a singleton a queue worker keeps between jobs,
     * so its copy may predate a demotion made by another process.
     */
    public function mayReceiveNotices(User $user): bool
    {
        $this->permissionResolver->forget($user);

        return $user->is_active && $this->permissionResolver->hasPermission($user, PermissionEnum::CanManageSettings->value);
    }
}
