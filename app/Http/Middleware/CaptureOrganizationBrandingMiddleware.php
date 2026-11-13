<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\OrganizationBrandingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers an organisation slug from `organization` / non-email `login_hint`
 * so login and consent can show that org's branding for the rest of the flow.
 */
final class CaptureOrganizationBrandingMiddleware
{
    public function __construct(
        private readonly OrganizationBrandingService $organizationBrandingService,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->organizationBrandingService->captureFromRequest($request);

        return $next($request);
    }
}
