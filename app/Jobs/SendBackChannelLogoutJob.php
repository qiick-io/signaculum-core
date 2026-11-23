<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\LogoutTokenRequestData;
use App\Services\LogoutTokenService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST one `logout_token` to a client's back-channel logout URI.
 *
 * Retries with backoff so a temporarily down RP still receives the logout; a
 * permanent failure is logged and does not affect other clients' jobs.
 *
 * Queued only after the surrounding transaction commits, so a rolled-back logout or
 * deactivation notifies nobody. Unique per client and `sid` while queued: dispatching the same
 * logout again (a retried deactivation, two logout paths for one session) sends it once.
 */
final class SendBackChannelLogoutJob implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * How long the uniqueness lock outlives a job that never ran (seconds).
     */
    public int $uniqueFor = 3600;

    public int $tries;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly string $clientId,
        public readonly string $backchannelLogoutUri,
        public readonly ?string $subject,
        public readonly ?string $oidcSessionId,
    ) {
        $this->tries = max(1, (int) config('oidc.backchannel_logout_tries', 3));
    }

    /**
     * A session without `sid` is identified by the subject, as in its logout token (§2.4).
     */
    public function uniqueId(): string
    {
        return $this->clientId.'|'.($this->oidcSessionId ?? 'sub:'.$this->subject);
    }

    /**
     * On an asynchronous queue a failed delivery throws, and the worker retries it with backoff.
     *
     * The sync queue runs the job inline, in the request that dispatched it (after the commit),
     * with no retry: a throw there would stop the logouts of every other relying party still
     * waiting in the same commit callbacks. Inline, the delivery is retried at once and a final
     * failure is logged without throwing.
     *
     * Side effect: an HTTP POST to the client's back-channel logout URI.
     */
    public function handle(LogoutTokenService $logoutTokenService): void
    {
        $logoutToken = $logoutTokenService->issue(new LogoutTokenRequestData(
            clientId: $this->clientId,
            subject: $this->subject,
            oidcSessionId: $this->oidcSessionId,
        ));

        if (! $this->job instanceof SyncJob) {
            $this->deliver($logoutToken, attempts: 1);

            return;
        }

        try {
            $this->deliver($logoutToken, attempts: $this->tries);
        } catch (ConnectionException|RequestException $exception) {
            $this->failed($exception);
        }
    }

    /**
     * @throws ConnectionException when the client cannot be reached on the last attempt
     * @throws RequestException when the client answers with an error on the last attempt
     */
    private function deliver(string $logoutToken, int $attempts): void
    {
        $timeout = (int) config('oidc.backchannel_logout_timeout', 5);

        $response = Http::asForm()
            ->timeout($timeout)
            ->connectTimeout($timeout)
            ->retry($attempts, 100, throw: false)
            ->post($this->backchannelLogoutUri, [
                'logout_token' => $logoutToken,
            ]);

        if ($response->failed()) {
            Log::warning('Back-channel logout delivery failed.', [
                'client_id' => $this->clientId,
                'uri' => $this->backchannelLogoutUri,
                'status' => $response->status(),
            ]);

            $response->throw();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Back-channel logout gave up after retries.', [
            'client_id' => $this->clientId,
            'uri' => $this->backchannelLogoutUri,
            'message' => $exception?->getMessage(),
        ]);
    }
}
