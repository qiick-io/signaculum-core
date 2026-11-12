<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountOverviewService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Home: where a direct login to Signaculum lands.
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request, AccountOverviewService $accountOverviewService): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('dashboard', $accountOverviewService->overviewFor($user, $request->session()->getId()));
    }
}
