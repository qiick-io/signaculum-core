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
 * Validates creation of an outbound webhook endpoint.
 */
final class StoreWebhookRequest extends FormRequest
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
        ];
    }

    public function toData(): WebhookData
    {
        /** @var list<string> $events */
        $events = array_values(array_unique($this->array('events')));

        return new WebhookData(
            url: $this->string('url')->toString(),
            events: $events,
            isActive: $this->boolean('is_active'),
        );
    }
}
