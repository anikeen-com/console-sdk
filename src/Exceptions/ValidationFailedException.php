<?php

namespace Anikeen\Console\Exceptions;

/**
 * 422: the Console refused the input; nothing was changed or ordered. errors() has the messages
 * per field (keys like "items.0.attributes.location_id"); `error` is set for refusals with a
 * code (e.g. "idempotency_key_reused").
 */
class ValidationFailedException extends ApiException
{
    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        $errors = $this->body()->errors ?? null;

        if (!is_object($errors) && !is_array($errors)) {
            return [];
        }

        return array_map(fn ($messages) => array_values(array_map('strval', (array) $messages)), (array) $errors);
    }

    /**
     * The first message for $field, null when it passed.
     */
    public function errorFor(string $field): ?string
    {
        return $this->errors()[$field][0] ?? null;
    }
}
