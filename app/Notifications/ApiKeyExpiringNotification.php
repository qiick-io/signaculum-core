<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ApiKey;
use App\Services\SettingsRepositoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail telling a key's owner (and its issuer, when someone else) that the key expires soon, so
 * it can be rotated or replaced before integrations break. Copy lives in `lang/<locale>/api_keys.php`.
 *
 * Carries only the key's public details (name, `key_prefix`, expiry): the queued payload holds
 * the model's identifier, and the hashes are hidden attributes, so no secret can travel here.
 * Mail only: this app has no notification bell (database channel) yet.
 */
final class ApiKeyExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ApiKey $apiKey,
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
            'name' => $this->apiKey->name,
            'prefix' => $this->apiKey->key_prefix,
            'app' => app(SettingsRepositoryService::class)->appName(),
            // ISO date: unambiguous in every locale.
            'date' => (string) $this->apiKey->expires_at?->toDateString(),
        ];

        return (new MailMessage)
            ->subject(__('api_keys.expiring.subject', $replacements, $locale))
            ->line(__('api_keys.expiring.intro', $replacements, $locale))
            ->line(__('api_keys.expiring.advice', [], $locale))
            ->action(__('api_keys.expiring.action', [], $locale), route('admin.api-keys.index'));
    }
}
