<?php

declare(strict_types=1);

namespace App\Data;

/**
 * One transactional email template: subject, body paragraphs, and action button label.
 */
final readonly class EmailTemplateData
{
    public function __construct(
        public string $subject,
        public string $body,
        public string $actionLabel,
    ) {}

    /**
     * @return array{subject: string, body: string, action_label: string}
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'body' => $this->body,
            'action_label' => $this->actionLabel,
        ];
    }
}
