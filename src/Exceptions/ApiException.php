<?php

namespace Anikeen\Console\Exceptions;

use Anikeen\Console\Result;
use Anikeen\Id\Exceptions\ApiException as IdApiException;
use Throwable;

/**
 * A request to the Console API failed. `status` is the HTTP status (0 when the Console could not
 * be reached), `error` the Console's stable error code when it sent one (e.g. "owner_only").
 * Extends anikeen/id's ApiException, so body(), isTemporary(), isNotFound() and isConflict() work
 * the same; the subclasses below cover the codes apps usually branch on.
 */
class ApiException extends IdApiException
{
    /**
     * The exception for a failed response, by status and `error` code.
     */
    public static function from(Result $result): self
    {
        $status = $result->status;
        $error = $result->errorCode();
        $message = rtrim($result->message(), '.');

        $class = match (true) {
            $status === 401 && $error === 'client_not_allowed' => ClientNotAllowedException::class,
            $status === 401 => AuthenticationException::class,
            $status === 403 && $error === 'missing_scope' => MissingScopeException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 && $error === 'account_conflict' => AccountConflictException::class,
            $status === 422 => ValidationFailedException::class,
            $status === 429 => RateLimitException::class,
            default => self::class,
        };

        $exception = new $class($message, $status, $error, $result->exception);

        if ($exception instanceof RateLimitException) {
            $exception->retryAfter = $result->retryAfter();
        }

        return $exception;
    }

    /**
     * The exception for a request that got no response (network error, timeout).
     */
    public static function unreachable(Throwable $previous): static
    {
        return new static(sprintf('The Anikeen Console is not reachable: %s', $previous->getMessage()), 0, null, $previous);
    }
}
