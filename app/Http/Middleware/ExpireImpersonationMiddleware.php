<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\StopUserImpersonationAction;
use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends an impersonation session whose TTL has elapsed and restores the actor.
 */
final class ExpireImpersonationMiddleware
{
    public function __construct(
        private readonly ImpersonationService $impersonationService,
        private readonly StopUserImpersonationAction $stopUserImpersonationAction,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->impersonationService->isExpired($request)) {
            try {
                $actor = $this->stopUserImpersonationAction->execute($request, isExpired: true);
            } catch (ValidationException) {
                $actor = null;
            }

            if ($actor === null) {
                return redirect()->route('login');
            }

            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => __('Impersonation expired. You are :name again.', ['name' => $actor->name]),
            ]);

            return redirect()->route('admin.users.index');
        }

        return $next($request);
    }
}
