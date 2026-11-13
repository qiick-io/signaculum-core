<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\EmailTemplateTypeEnum;
use App\Models\Organization;
use App\Services\EmailTemplateService;
use App\Services\SettingsRepositoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email for a pending organisation invitation (contains the one-time accept URL).
 * Copy comes from EmailTemplateService (defaults + instance-admin overrides).
 */
final class OrganizationInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Organization $organization,
        private readonly string $acceptUrl,
        ?string $preferredLocale = null,
    ) {
        if ($preferredLocale !== null && $preferredLocale !== '') {
            $this->locale($preferredLocale);
        }
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = is_string($this->locale) && $this->locale !== ''
            ? $this->locale
            : app(SettingsRepositoryService::class)->defaultLanguage();

        return app(EmailTemplateService::class)->mailMessage(
            EmailTemplateTypeEnum::Invitation,
            $locale,
            [
                'app_name' => app(SettingsRepositoryService::class)->appName(),
                'organization_name' => $this->organization->name,
                'url' => $this->acceptUrl,
            ],
        );
    }
}
