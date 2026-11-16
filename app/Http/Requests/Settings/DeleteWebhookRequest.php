<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Traits\AuthorizesInstanceSettingsTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises deletion of a webhook endpoint (instance admins only).
 */
final class DeleteWebhookRequest extends FormRequest
{
    use AuthorizesInstanceSettingsTrait;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
