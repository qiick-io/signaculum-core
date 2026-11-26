<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Validated payload for creating or updating a webhook endpoint.
 */
final readonly class WebhookData
{
    /**
     * @param  list<string>  $events
     */
    public function __construct(
        public string $url,
        public array $events,
        public bool $isActive,
    ) {}
}
