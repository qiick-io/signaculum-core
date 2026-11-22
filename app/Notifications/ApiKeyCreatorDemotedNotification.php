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
 * Mail to an instance admin: an Admin API key was created by someone who is no longer an
 * instance admin. The key still works, so the mail links to the console to revoke it; after
 * the creator's deletion the key was revoked and the mail says so. Copy lives in
 * `lang/<locale>/api_keys.php`.
 *
 * Carries the key's public details and the creator as text (name and email): a deleted
 * creator could not be restored from the queue, and no secret travels here.
 */
final class ApiKeyCreatorDemotedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ApiKey $apiKey,
        public readonly string $creatorLabel,
        public readonly bool $isRevoked,
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
        $recipientLocale = data_get($notifiable, 'locale');
        $locale = is_string($recipientLocale) ? $recipientLocale : config()->string('app.locale');

        $copy = $this->isRevoked ? 'api_keys.creator_deleted' : 'api_keys.creator_demoted';
        $replacements = [
            'name' => $this->apiKey->name,
            'prefix' => $this->apiKey->key_prefix,
            'creator' => $this->creatorLabel,
            'app' => app(SettingsRepositoryService::class)->appName(),
        ];

        return (new MailMessage)
            ->subject(__("{$copy}.subject", $replacements, $locale))
            ->line(__("{$copy}.intro", $replacements, $locale))
            ->line(__("{$copy}.advice", [], $locale))
            ->action(__("{$copy}.action", [], $locale), route('admin.api-keys.index'));
    }
}
