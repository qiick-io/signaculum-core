<?php

declare(strict_types=1);

namespace App\Services\Access;

use Illuminate\Http\Request;
use Qiick\Access\Contracts\AuthorizationResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The refusal of a missing permission ({@see AuthorizationResponder}): Signaculum's 403 error page
 * (or JSON on a machine door), rendered by the exception handler like every other 403.
 */
final class AccessAuthorizationResponderService implements AuthorizationResponder
{
    /**
     * @throws HttpException always (403)
     */
    public function forbidden(Request $request, string $permission): Response
    {
        abort(403);
    }
}
