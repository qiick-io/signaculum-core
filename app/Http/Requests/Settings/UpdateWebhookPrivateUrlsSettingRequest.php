<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Traits\AuthorizesInstanceSettingsTrait;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the instance toggle that allows webhook URLs on private networks.
 */
final class UpdateWebhookPrivateUrlsSettingRequest extends FormRequest
{
    use AuthorizesInstanceSettingsTrait;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'webhooks_allow_private_urls' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array{webhooks_allow_private_urls: bool}
     */
    public function settings(): array
    {
        return [
            'webhooks_allow_private_urls' => $this->boolean('webhooks_allow_private_urls'),
        ];
    }
}
