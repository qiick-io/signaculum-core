<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Typed confirmation before revoking an OIDC application.
 */
final class DeleteApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Client $application */
        $application = $this->route('application');

        return Gate::allows('delete', $application);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Client $application */
            $application = $this->route('application');

            if ($this->string('confirmation')->toString() !== $application->name) {
                $validator->errors()->add(
                    'confirmation',
                    __('Type the application name to confirm deletion.'),
                );
            }
        });
    }
}
