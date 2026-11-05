<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Scopes Signaculum grants (OIDC Core §5.4). `qiick.orgs` joins with the organisations.
 */
enum OidcScopeEnum: string
{
    case OpenId = 'openid';
    case Profile = 'profile';
    case Email = 'email';

    /**
     * Text shown on the consent screen.
     */
    public function description(): string
    {
        return match ($this) {
            self::OpenId => 'Sign you in with your Signaculum account',
            self::Profile => 'See your name',
            self::Email => 'See your email address',
        };
    }

    /**
     * Passport scope map (`Passport::tokensCan()`).
     *
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        $descriptionsByScope = [];

        foreach (self::cases() as $scope) {
            $descriptionsByScope[$scope->value] = $scope->description();
        }

        return $descriptionsByScope;
    }
}
