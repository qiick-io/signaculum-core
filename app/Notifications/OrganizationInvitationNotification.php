<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email for a pending organisation invitation (contains the one-time accept URL).
 */
final class OrganizationInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Organization $organization,
        private readonly string $acceptUrl,
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
            ->subject(__('You are invited to :name', ['name' => $this->organization->name]))
            ->line(__('You have been invited to join the organisation :name on Signaculum.', [
                'name' => $this->organization->name,
            ]))
            ->action(__('Accept invitation'), $this->acceptUrl)
            ->line(__('This link expires. If you did not expect this email, you can ignore it.'));
    }
}
