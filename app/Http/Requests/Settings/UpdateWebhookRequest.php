<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Data\WebhookData;
use App\Enums\WebhookEventEnum;
use App\Traits\AuthorizesInstanceSettingsTrait;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates updates to an outbound webhook endpoint.
 */
final class UpdateWebhookRequest extends FormRequest
{
    use AuthorizesInstanceSettingsTrait;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', Rule::in(WebhookEventEnum::values())],
            'is_active' => ['required', 'boolean'],
            'secret' => ['nullable', 'string', 'min:16', 'max:128'],
        ];
    }

    public function toData(): WebhookData
    {
        /** @var list<string> $events */
        $events = array_values(array_unique($this->array('events')));
        $secret = $this->input('secret');

        return new WebhookData(
            url: $this->string('url')->toString(),
            events: $events,
            isActive: $this->boolean('is_active'),
            secret: is_string($secret) && $secret !== '' ? $secret : null,
        );
    }
}
