<?php

namespace Anikeen\Console\Exceptions;

/**
 * 403 "missing_scope": the token lacks the scope for this family, e.g. "console-containers".
 * Ask the user to sign in again with that scope.
 */
class MissingScopeException extends ApiException
{
    /**
     * The scope the Console asked for, null when it named none.
     */
    public function scope(): ?string
    {
        $body = $this->body();

        foreach (['scope', 'required_scope'] as $key) {
            if (isset($body->{$key}) && is_string($body->{$key})) {
                return $body->{$key};
            }
        }

        if (isset($body->scopes) && is_array($body->scopes) && is_string($body->scopes[0] ?? null)) {
            return $body->scopes[0];
        }

        return preg_match('/scope "([^"]+)"/', $this->getMessage(), $match) ? $match[1] : null;
    }
}
