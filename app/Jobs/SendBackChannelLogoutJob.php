<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\LogoutTokenRequestData;
use App\Services\LogoutTokenService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST one `logout_token` to a client's back-channel logout URI.
 *
 * Retries with backoff so a temporarily down RP still receives the logout; a
 * permanent failure is logged and does not affect other clients' jobs.
 */
final class SendBackChannelLogoutJob implements ShouldQueue
{
    use Queueable;

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

    public function handle(LogoutTokenService $logoutTokenService): void
    {
        $logoutToken = $logoutTokenService->issue(new LogoutTokenRequestData(
            clientId: $this->clientId,
            subject: $this->subject,
            oidcSessionId: $this->oidcSessionId,
        ));

        $timeout = (int) config('oidc.backchannel_logout_timeout', 5);

        try {
            $response = Http::asForm()
                ->timeout($timeout)
                ->connectTimeout($timeout)
                ->post($this->backchannelLogoutUri, [
                    'logout_token' => $logoutToken,
                ]);
        } catch (ConnectionException $exception) {
            throw $exception;
        }

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
