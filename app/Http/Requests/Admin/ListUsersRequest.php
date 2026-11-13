<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Data\UserFilterData;
use App\Http\Requests\Concerns\ValidatesListPerPageTrait;
use App\Http\Requests\Concerns\ValidatesSearchQueryTrait;
use App\Models\User;
use App\Services\UserQueryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Authorizes and normalizes the admin users index query string.
 */
final class ListUsersRequest extends FormRequest
{
    use ValidatesListPerPageTrait;
    use ValidatesSearchQueryTrait;

    public function authorize(): bool
    {
        return Gate::allows('viewAny', User::class);
    }

    /**
     * Query params are soft-validated in {@see filters()}; empty rules keep FormRequest happy.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    public function filters(): UserFilterData
    {
        $sort = $this->string('sort')->toString();
        $sort = in_array($sort, UserQueryService::SORTABLE_COLUMNS, true) ? $sort : 'created_at';
        $direction = $this->string('direction', 'desc')->toString() === 'asc' ? 'asc' : 'desc';
        $status = $this->string('status')->toString();
        $status = in_array($status, ['active', 'deactivated'], true) ? $status : 'active';

        return new UserFilterData(
            search: $this->validatedSearch($this),
            status: $status,
            sort: $sort,
            direction: $direction,
            perPage: $this->validatedListPerPage($this),
        );
    }
}
