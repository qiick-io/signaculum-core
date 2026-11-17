<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Listeners\EndOidcSessionOnLogoutListener;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * After Fortify web logout, render front-channel iframes when the session used
 * clients that registered `frontchannel_logout_uri` (OIDC Front-Channel Logout 1.0 §2).
 *
 * URLs are stashed on the request by {@see EndOidcSessionOnLogoutListener}
 * before the session is invalidated.
 */
final class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        $iframeUrls = $request->attributes->get('oidc.frontchannel_logout_urls');

        if (! is_array($iframeUrls) || $iframeUrls === []) {
            return redirect(Fortify::redirects('logout', '/'));
        }

        /** @var list<mixed> $iframeUrls */
        $urls = array_values(array_filter(
            $iframeUrls,
            static fn (mixed $url): bool => is_string($url) && $url !== '',
        ));

        if ($urls === []) {
            return redirect(Fortify::redirects('logout', '/'));
        }

        $request->attributes->set(
            'security.frontchannel_frame_origins',
            $this->originsOf($urls),
        );

        return Inertia::render('oauth/frontchannel-logout', [
            'iframeUrls' => $urls,
            'continueUrl' => url(Fortify::redirects('logout', '/')),
        ])->toResponse($request);
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function originsOf(array $urls): array
    {
        $origins = [];

        foreach ($urls as $url) {
            $scheme = parse_url($url, PHP_URL_SCHEME);
            $host = parse_url($url, PHP_URL_HOST);
            $port = parse_url($url, PHP_URL_PORT);

            if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
                continue;
            }

            $origins[] = $scheme.'://'.$host.(is_int($port) ? ':'.$port : '');
        }

        return array_values(array_unique($origins));
    }
}
