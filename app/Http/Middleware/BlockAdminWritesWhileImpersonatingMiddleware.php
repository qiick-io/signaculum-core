<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While impersonating, refuse mutating requests under `/admin/*` so the actor
 * cannot change instance state as the target (security review #208).
 */
final class BlockAdminWritesWhileImpersonatingMiddleware
{
    public function __construct(
        private readonly ImpersonationService $impersonationService,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $this->impersonationService->isImpersonating($request)
            && $request->is('admin/*')
            && ! $request->isMethodSafe()
        ) {
            abort(403, 'Cannot change admin resources while impersonating.');
        }

        return $next($request);
    }
}
