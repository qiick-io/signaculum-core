<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Data\ApplicationFilterData;
use App\Http\Requests\Concerns\ValidatesListPerPageTrait;
use App\Http\Requests\Concerns\ValidatesSearchQueryTrait;
use App\Models\Client;
use App\Services\ApplicationQueryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Authorizes and normalizes the admin applications index query string.
 */
final class ListApplicationsRequest extends FormRequest
{
    use ValidatesListPerPageTrait;
    use ValidatesSearchQueryTrait;

    public function authorize(): bool
    {
        return Gate::allows('viewAny', Client::class);
    }

    /**
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    public function filters(): ApplicationFilterData
    {
        $sort = $this->string('sort')->toString();
        $sort = in_array($sort, ApplicationQueryService::SORTABLE_COLUMNS, true) ? $sort : 'created_at';
        $direction = $this->string('direction', 'desc')->toString() === 'asc' ? 'asc' : 'desc';

        return new ApplicationFilterData(
            search: $this->validatedSearch($this),
            sort: $sort,
            direction: $direction,
            perPage: $this->validatedListPerPage($this),
        );
    }
}
