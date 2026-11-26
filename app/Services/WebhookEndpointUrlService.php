<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Ssrf\UrlGuardOptions;

/**
 * Webhook destinations through qiick/laravel-outbound's SSRF guard, with Signaculum's instance
 * setting "allow private webhook URLs" (Settings → Webhooks): off, an endpoint must resolve to a
 * public address; on, private and loopback addresses are allowed for every endpoint. The same
 * guard pins the address again on every delivery attempt.
 */
final class WebhookEndpointUrlService
{
    /**
     * Held while the private-hosts setting is read and an endpoint flag written from it.
     */
    public const string PRIVATE_HOSTS_LOCK = 'webhooks:private-hosts-setting';

    private const int LOCK_SECONDS = 30;

    private const int LOCK_WAIT_SECONDS = 5;

    public function __construct(
        private readonly RemoteUrlGuard $remoteUrlGuard,
        private readonly SettingsRepositoryService $settingsRepositoryService,
    ) {}

    /**
     * @throws ValidationException on `url` when the guard refuses the destination
     */
    public function assertSafe(string $url): void
    {
        [$isAllowed, $reason] = $this->authorization($url);

        if (! $isAllowed) {
            throw ValidationException::withMessages(['url' => __('Webhook URL is not allowed: :reason', ['reason' => $reason])]);
        }
    }

    /**
     * @return array{0: bool, 1: string}
     */
    public function authorization(string $url): array
    {
        $result = $this->remoteUrlGuard->tryAuthorize($url, new UrlGuardOptions(allowPrivateHosts: $this->isPrivateHostsAllowed()));

        return $result['ok'] ? [true, ''] : [false, $result['reason']];
    }

    /**
     * Read from the database every time, never from the settings repository's copy: another
     * request or process may have turned it off since this one first read it.
     */
    public function isPrivateHostsAllowed(): bool
    {
        $this->settingsRepositoryService->forgetReadValues();

        return $this->settingsRepositoryService->areWebhookPrivateUrlsAllowed();
    }

    /**
     * Saves the instance setting and copies it onto every endpoint, which the delivery worker
     * reads when it claims a delivery: one transaction, so a worker sees the old pair or the new
     * one, never a mix, and an interrupted save changes neither. Run under
     * {@see self::PRIVATE_HOSTS_LOCK}, so a concurrent create never stores the old value after it.
     *
     * Side effects: writes the `webhooks_allow_private_urls` setting and `allow_private_hosts` on
     * every endpoint, the soft-deleted ones included.
     */
    public function savePrivateHostsSetting(bool $isAllowed): void
    {
        $this->whilePrivateHostsSettingIsLocked(fn () => DB::transaction(function () use ($isAllowed): void {
            $this->settingsRepositoryService->save(['webhooks_allow_private_urls' => $isAllowed]);
            // A query update: `allow_private_hosts` is not mass assignable in the package.
            WebhookEndpoint::withTrashed()->update(['allow_private_hosts' => $isAllowed]);
        }));
    }

    /**
     * Runs `$callback` while no one else reads-then-writes the private-hosts setting.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws LockTimeoutException when the lock stays taken for LOCK_WAIT_SECONDS
     */
    public function whilePrivateHostsSettingIsLocked(Closure $callback): mixed
    {
        return Cache::lock(self::PRIVATE_HOSTS_LOCK, self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, $callback);
    }
}
