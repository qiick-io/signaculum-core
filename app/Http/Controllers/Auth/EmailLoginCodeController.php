<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\SendEmailLoginCodeAction;
use App\Actions\VerifyEmailLoginCodeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendEmailLoginCodeRequest;
use App\Http\Requests\Auth\VerifyEmailLoginCodeRequest;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Passwordless sign-in with a one-time email code (#121). Off unless the instance enables it.
 */
final class EmailLoginCodeController extends Controller
{
    public function create(Request $request, SettingsRepositoryService $settings): Response
    {
        abort_unless($settings->isEmailCodeLoginEnabled(), 404);

        return Inertia::render('auth/email-code', [
            'email' => (string) $request->query('email', ''),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(
        SendEmailLoginCodeRequest $request,
        SendEmailLoginCodeAction $sendEmailLoginCodeAction,
    ): RedirectResponse {
        $email = strtolower(trim((string) $request->validated('email')));
        $sendEmailLoginCodeAction->execute($email);

        return to_route('login.email-code.show', ['email' => $email])
            ->with('status', __('If an account exists for that email, a sign-in code has been sent.'));
    }

    public function verify(
        VerifyEmailLoginCodeRequest $request,
        VerifyEmailLoginCodeAction $verifyEmailLoginCodeAction,
    ): RedirectResponse {
        $verifyEmailLoginCodeAction->execute(
            (string) $request->validated('email'),
            (string) $request->validated('code'),
        );

        return redirect()->intended(route('dashboard'));
    }
}
