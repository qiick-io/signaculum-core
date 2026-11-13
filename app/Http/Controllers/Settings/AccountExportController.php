<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings → Profile: download a JSON export of the signed-in user's account data.
 */
final class AccountExportController extends Controller
{
    public function __invoke(Request $request, AccountExportService $accountExportService): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        $payload = $accountExportService->exportFor($user, $request->session()->getId());
        $filename = 'signaculum-account-export-'.now()->format('Y-m-d').'.json';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return response()->streamDownload(
            function () use ($json): void {
                echo $json;
            },
            $filename,
            ['Content-Type' => 'application/json'],
        );
    }
}
