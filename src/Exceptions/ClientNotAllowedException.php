<?php

namespace Anikeen\Console\Exceptions;

/**
 * 401 "client_not_allowed": the token belongs to an app the Console does not accept (not a
 * trusted app). A new token does not help; Anikeen has to trust the app.
 */
class ClientNotAllowedException extends AuthenticationException
{
}
