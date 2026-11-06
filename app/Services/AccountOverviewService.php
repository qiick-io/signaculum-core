<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ConnectedApplicationData;
use App\Models\User;

/**
 * The key numbers on the Home page: how many browsers are signed in, how many
 * applications hold access, and how well the account is protected.
 */
final class AccountOverviewService
{
    public function __construct(
        private readonly BrowserSessionService $browserSessionService,
        private readonly ConnectedApplicationService $connectedApplicationService,
    ) {}

    /**
     * @return array{
     *     activeSessionCount: int,
     *     connectedApplications: list<ConnectedApplicationData>,
     *     hasTwoFactor: bool,
     *     passkeyCount: int,
     * }
     */
    public function overviewFor(User $user, string $currentSessionId): array
    {
        return [
            'activeSessionCount' => max(1, count($this->browserSessionService->sessionsFor($user, $currentSessionId))),
            'connectedApplications' => $this->connectedApplicationService->applicationsFor($user),
            'hasTwoFactor' => $user->hasEnabledTwoFactorAuthentication(),
            'passkeyCount' => $user->passkeys()->count(),
        ];
    }
}
