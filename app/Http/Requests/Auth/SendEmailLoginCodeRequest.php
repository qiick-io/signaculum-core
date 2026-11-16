<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Services\SettingsRepositoryService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Request a one-time email sign-in code.
 */
final class SendEmailLoginCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(SettingsRepositoryService::class)->isEmailCodeLoginEnabled();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
        ];
    }
}
