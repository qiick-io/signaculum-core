<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail with the opaque accept URL for an organisation invitation.
 *
 * The plaintext token is kept only for the notification (and tests); the database
 * stores its sha256 hash.
 */
final class OrganizationInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Organization $organization,
        public readonly string $plainToken,
        public readonly string $acceptUrl,
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
        return (new MailMessage)
            ->subject(__('Invitation to :organization', ['organization' => $this->organization->name]))
            ->line(__('You have been invited to join :organization on Signaculum.', [
                'organization' => $this->organization->name,
            ]))
            ->action(__('Accept invitation'), $this->acceptUrl)
            ->line(__('This invitation expires soon. If you did not expect it, you can ignore this email.'));
    }
}
