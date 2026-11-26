<?php

declare(strict_types=1);

namespace App\Data;

use Qiick\Outbound\Models\WebhookEndpoint;

/**
 * A newly created webhook endpoint and its plain `whsec_` signing secret, shown once. The secret
 * never becomes a model attribute, so no later save of the endpoint can write it anywhere.
 */
final readonly class IssuedWebhookData
{
    public function __construct(
        public WebhookEndpoint $endpoint,
        #[\SensitiveParameter] public string $plainSecret,
    ) {}
}
