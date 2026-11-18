<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ActivityEventEnum;
use App\Services\SettingsRepositoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Queued security alert to the account owner when a second factor is enrolled, confirmed,
 * removed or its recovery codes are regenerated (#168).
 *
 * A password alone can manage second factors, so this mail is how the owner learns that
 * someone else did. It names the kind of change only: never a passkey name, secret or code,
 * since those are chosen by (or useful to) whoever made the change.
 */
final class SecondFactorChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ActivityEventEnum $secondFactorChange,
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

        $replacements = [
            'app' => app(SettingsRepositoryService::class)->appName(),
            'email' => (string) data_get($notifiable, 'email', ''),
        ];

        return (new MailMessage)
            ->subject(__('second_factors.changed.subject', $replacements, $locale))
            ->line(__('second_factors.changed.intro', $replacements, $locale))
            ->line(__('second_factors.changes.'.$this->secondFactorChange->value, [], $locale))
            ->line(__('second_factors.changed.advice', [], $locale))
            ->action(__('second_factors.changed.action', [], $locale), route('security.edit'));
    }
}
