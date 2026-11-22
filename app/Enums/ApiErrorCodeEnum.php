<?php

declare(strict_types=1);

namespace App\Enums;

use App\Exceptions\InvalidIdTokenHintException;
use App\Exceptions\LastOrganizationOwnerException;
use App\Exceptions\SecondFactorUnavailableException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Stable machine codes added to Laravel's own JSON error response on the product REST API
 * (and `/mcp`, when installed). Clients branch on `error`, never on the translated `message`.
 *
 * This is the fleet standard from `knowledge/conventions/standards-register.md`: one enum,
 * one mapping, documented in `docs/api-errors.md`. Values are wire contract and never change.
 * OAuth/OIDC endpoints are out of scope: they keep RFC 6749/6750 error bodies instead.
 */
enum ApiErrorCodeEnum: string
{
    case BadRequest = 'bad_request';
    case ValidationFailed = 'validation_failed';
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case TooManyRequests = 'too_many_requests';
    case ServerError = 'server_error';

    /**
     * The single exception-to-code registry. Framework types are mapped by type; an
     * `HttpExceptionInterface` with no explicit case is mapped by status. Anything else is
     * deliberately a `server_error`, so an unexpected application fault cannot be mistaken
     * for a client mistake.
     *
     * @var array<class-string<Throwable>, self>
     */
    private const array EXCEPTION_CODES = [
        ValidationException::class => self::ValidationFailed,
        AuthenticationException::class => self::Unauthenticated,
        AccessDeniedHttpException::class => self::Forbidden,
        AuthorizationException::class => self::Forbidden,
        ModelNotFoundException::class => self::NotFound,
        NotFoundHttpException::class => self::NotFound,
        MethodNotAllowedHttpException::class => self::MethodNotAllowed,
        ThrottleRequestsException::class => self::TooManyRequests,
        InvalidIdTokenHintException::class => self::Unauthenticated,
        LastOrganizationOwnerException::class => self::Forbidden,
        SecondFactorUnavailableException::class => self::Forbidden,
    ];

    /**
     * Resolve the code of an exception rendered by Laravel's handler. `ModelNotFoundException`
     * and `AuthorizationException` arrive here after `Handler::prepareException()` has mapped
     * them to their HTTP counterparts, but both are listed so a direct throw maps correctly too.
     */
    public static function forException(Throwable $exception): self
    {
        foreach (self::EXCEPTION_CODES as $exceptionClass => $code) {
            if ($exception instanceof $exceptionClass) {
                return $code;
            }
        }

        if ($exception instanceof HttpExceptionInterface) {
            return self::forStatus($exception->getStatusCode());
        }

        return self::ServerError;
    }

    /**
     * Code of an HTTP status with no more specific exception type. Every other 4xx is a bad
     * request and every other 5xx is a server error; this keeps the enum small without
     * inventing a code per RFC status.
     */
    public static function forStatus(int $status): self
    {
        return match ($status) {
            401 => self::Unauthenticated,
            403 => self::Forbidden,
            404 => self::NotFound,
            405 => self::MethodNotAllowed,
            422 => self::ValidationFailed,
            429 => self::TooManyRequests,
            default => $status >= 500 ? self::ServerError : self::BadRequest,
        };
    }

    /**
     * Application exception classes that must have an explicit code. The coverage test fails
     * when a new `App\Exceptions` class is added without registering it here.
     *
     * @return array<class-string<Throwable>, self>
     */
    public static function applicationExceptionCodes(): array
    {
        return [
            InvalidIdTokenHintException::class => self::Unauthenticated,
            LastOrganizationOwnerException::class => self::Forbidden,
            SecondFactorUnavailableException::class => self::Forbidden,
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
