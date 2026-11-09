<?php

namespace Anikeen\Console\Exceptions;

/**
 * 401: the token is missing, invalid, expired or revoked and could not be refreshed (the previous
 * exception then is anikeen/id's RequestFreshAccessTokenException). Send the user through the
 * Anikeen ID login again.
 */
class AuthenticationException extends ApiException
{
}
