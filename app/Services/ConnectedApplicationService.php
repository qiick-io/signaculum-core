<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ConnectedApplicationData;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

/**
 * The applications a user has authorized and that can still act for them: a client
 * counts while it holds an unexpired, unrevoked access token, or a live refresh token.
 * Passport also skips the consent screen for exactly these clients.
 */
final class ConnectedApplicationService
{
    /**
     * @return list<ConnectedApplicationData> most recently authorized first
     */
    public function applicationsFor(User $user): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, Token> $liveTokens */
        $liveTokens = $this->liveTokensQuery($user)->with('client')->get();

        return array_values($liveTokens
            ->groupBy('client_id')
            ->map(function ($clientTokens): ConnectedApplicationData {
                /** @var Collection<int, Token> $clientTokens */
                $firstToken = $clientTokens->first();

                return new ConnectedApplicationData(
                    clientId: (string) $firstToken->client_id,
                    name: (string) ($firstToken->client->name ?? $firstToken->client_id),
                    scopes: array_values($clientTokens->pluck('scopes')->flatten()->unique()->sort()->values()->all()),
                    firstAuthorizedAt: (int) $clientTokens->min(fn (Token $token): int => (int) $token->created_at?->getTimestamp()),
                    lastAuthorizedAt: (int) $clientTokens->max(fn (Token $token): int => (int) $token->created_at?->getTimestamp()),
                );
            })
            ->sortByDesc(fn (ConnectedApplicationData $application): int => $application->lastAuthorizedAt)
            ->all());
    }

    /**
     * Unrevoked access tokens of the user that are unexpired, or whose refresh token is.
     *
     * @return Builder<Token>
     */
    public function liveTokensQuery(User $user): Builder
    {
        $now = Date::now();

        return Passport::token()->newQuery()
            ->where('user_id', $user->getKey())
            ->where('revoked', false)
            ->where(function (Builder $liveQuery) use ($now): void {
                $liveQuery
                    ->where('expires_at', '>', $now)
                    ->orWhereHas('refreshToken', function (Builder $refreshTokenQuery) use ($now): void {
                        $refreshTokenQuery->where('revoked', false)->where('expires_at', '>', $now);
                    });
            });
    }
}
