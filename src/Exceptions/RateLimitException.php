<?php

namespace Anikeen\Console\Exceptions;

/**
 * 429: too many requests (60 per minute and user) or orders ("rate_limited", 10 per minute and
 * team). Try again after `retryAfter` seconds.
 */
class RateLimitException extends ApiException
{
    /**
     * Seconds to wait (the `Retry-After` header), null when the Console sent none.
     */
    public ?int $retryAfter = null;
}
