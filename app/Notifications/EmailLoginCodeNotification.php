<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\EmailTemplateTypeEnum;
use App\Services\EmailTemplateService;
use App\Services\SettingsRepositoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Queued mail with the plain one-time sign-in code (never persisted except as a hash).
 */
final class EmailLoginCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $plainCode,
        public readonly int $expireMinutes,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = is_string(data_get($notifiable, 'locale'))
            ? (string) data_get($notifiable, 'locale')
            : (string) config('app.locale', 'en');

        $userName = trim((string) data_get($notifiable, 'first_name', '').' '.(string) data_get($notifiable, 'last_name', ''));

        return app(EmailTemplateService::class)->mailMessage(
            EmailTemplateTypeEnum::EmailLoginCode,
            $locale,
            [
                'app_name' => app(SettingsRepositoryService::class)->appName(),
                'user_name' => $userName !== '' ? $userName : (string) data_get($notifiable, 'email', ''),
                'code' => $this->plainCode,
                'expire_minutes' => $this->expireMinutes,
            ],
        );
    }
}
