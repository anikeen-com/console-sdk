<?php

namespace Anikeen\Console\Exceptions;

/**
 * 409 "account_conflict": the Console could not create the user's account for the app, because
 * another Console account already uses the e-mail address. The user has to sign in to the Console
 * once (or contact support).
 */
class AccountConflictException extends ApiException
{
}
