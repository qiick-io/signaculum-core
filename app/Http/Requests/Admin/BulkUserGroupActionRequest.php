<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\PermissionEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Qiick\Access\Http\Concerns\AuthorizesWithPermission;

/**
 * Validate bulk restore / force-delete of soft-deleted user groups.
 */
class BulkUserGroupActionRequest extends FormRequest
{
    use AuthorizesWithPermission;

    public function authorize(): bool
    {
        $action = $this->input('action');

        if ($action === 'restore') {
            $this->authorizePermission(PermissionEnum::CanRestoreGroups->value);
        } elseif ($action === 'force_delete') {
            $this->authorizePermission(PermissionEnum::CanForceDeleteGroups->value);
        } else {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['restore', 'force_delete'])],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:user_groups,id'],
        ];
    }
}
