<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * Build a GDPR-style JSON export of the signed-in user's account data.
 *
 * Never includes password hashes, remember tokens, or two-factor secrets.
 */
final class AccountExportService
{
    public function __construct(
        private readonly BrowserSessionService $browserSessionService,
        private readonly ConnectedApplicationService $connectedApplicationService,
    ) {}

    /**
     * @return array{
     *     exported_at: string,
     *     profile: array{subject: string|null, email: string|null, email_verified_at: string|null, first_name: string|null, last_name: string|null, locale: string|null, is_instance_admin: bool, created_at: string|null},
     *     organisations: list<array{id: string, name: string, slug: string, roles: list<string>}>,
     *     sessions: list<array{public_id: string, ip_address: string|null, browser: string, platform: string, is_current_device: bool, last_active_at: string}>,
     *     connected_applications: list<array{client_id: string, name: string, scopes: list<string>, first_authorized_at: string, last_authorized_at: string}>
     * }
     */
    public function exportFor(User $user, string $currentSessionId): array
    {
        $memberships = OrganizationMembership::query()
            ->with('organization:id,name,slug')
            ->where('user_id', $user->getKey())
            ->get();

        /** @var list<array{id: string, name: string, slug: string, roles: list<string>}> $organisations */
        $organisations = [];

        foreach ($memberships as $membership) {
            $organization = $membership->organization;

            if ($organization === null) {
                continue;
            }

            $organisations[] = [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'roles' => $membership->roles,
            ];
        }

        /** @var list<array{public_id: string, ip_address: string|null, browser: string, platform: string, is_current_device: bool, last_active_at: string}> $sessions */
        $sessions = [];

        foreach ($this->browserSessionService->sessionsFor($user, $currentSessionId) as $session) {
            $sessions[] = [
                'public_id' => $session->publicId,
                'ip_address' => $session->ipAddress,
                'browser' => $session->browser,
                'platform' => $session->platform,
                'is_current_device' => $session->isCurrentDevice,
                'last_active_at' => Date::createFromTimestamp($session->lastActiveAt)->toIso8601String(),
            ];
        }

        /** @var list<array{client_id: string, name: string, scopes: list<string>, first_authorized_at: string, last_authorized_at: string}> $connectedApplications */
        $connectedApplications = [];

        foreach ($this->connectedApplicationService->applicationsFor($user) as $application) {
            $connectedApplications[] = [
                'client_id' => $application->clientId,
                'name' => $application->name,
                'scopes' => $application->scopes,
                'first_authorized_at' => Date::createFromTimestamp($application->firstAuthorizedAt)->toIso8601String(),
                'last_authorized_at' => Date::createFromTimestamp($application->lastAuthorizedAt)->toIso8601String(),
            ];
        }

        return [
            'exported_at' => Date::now()->toIso8601String(),
            'profile' => [
                'subject' => $user->subject,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'locale' => $user->locale,
                'is_instance_admin' => $user->isSuperAdmin(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'organisations' => $organisations,
            'sessions' => $sessions,
            'connected_applications' => $connectedApplications,
        ];
    }
}
