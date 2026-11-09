<?php

namespace Anikeen\Console;

use Anikeen\Id\Helpers\Paginator;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use Throwable;

/**
 * One response of the Console API. Lists come in two shapes, both read here: API resources
 * (`data`, `links`, `meta`) and Laravel's plain paginator (`data`, `current_page`, `last_page`,
 * `next_page_url`, …); both end up in `meta` / `links` and the `paginator`.
 */
class Result
{
    /**
     * Was the API call successful (2xx)?
     */
    public bool $success;

    /**
     * HTTP status code, 0 without a response.
     */
    public int $status;

    /**
     * The decoded JSON body, null for an empty or non-JSON body.
     */
    public mixed $body = null;

    /**
     * `data` of the body (a list for paginated responses), else the whole body.
     */
    public mixed $data = null;

    /**
     * Pagination meta (current_page, last_page, per_page, total), null when not paginated.
     */
    public ?stdClass $meta = null;

    /**
     * Pagination links (first, last, prev, next), null when not paginated.
     */
    public ?stdClass $links = null;

    /**
     * Paginator for the next / previous page, null when not paginated.
     */
    public ?Paginator $paginator = null;

    public function __construct(
        public readonly ?ResponseInterface $response,
        public readonly ?Throwable $exception = null,
    ) {
        $this->status = $response?->getStatusCode() ?? 0;
        $this->success = $exception === null && $this->status >= 200 && $this->status < 300;

        $raw = $response ? (string) $response->getBody() : '';
        $json = $raw !== '' ? json_decode($raw, false) : null;
        $this->body = $json;

        if (!$json instanceof stdClass) {
            $this->data = $json;

            return;
        }

        if (property_exists($json, 'data') && ($json->meta ?? null) instanceof stdClass) {
            // API resource collection
            $this->data = (array) $json->data;
            $this->meta = $json->meta;
            $this->links = ($json->links ?? null) instanceof stdClass ? $json->links : null;
        } elseif (property_exists($json, 'data') && isset($json->current_page)) {
            // Laravel's plain paginator
            $this->data = (array) $json->data;
            $this->meta = (object) [
                'current_page' => $json->current_page,
                'last_page' => $json->last_page ?? null,
                'per_page' => $json->per_page ?? null,
                'total' => $json->total ?? null,
            ];
            $this->links = (object) [
                'first' => $json->first_page_url ?? null,
                'last' => $json->last_page_url ?? null,
                'prev' => $json->prev_page_url ?? null,
                'next' => $json->next_page_url ?? null,
            ];
        } else {
            $this->data = property_exists($json, 'data') ? $json->data : $json;
        }

        if ($this->meta !== null) {
            $this->paginator = new Paginator($this->links, $this->meta);
        }
    }

    public function success(): bool
    {
        return $this->success;
    }

    /**
     * The API's `message`, else the transport error.
     */
    public function message(): string
    {
        $message = $this->body->message ?? null;

        if (is_string($message) && $message !== '') {
            return $message;
        }

        return $this->exception?->getMessage() ?: 'Anikeen Console API request failed';
    }

    /**
     * The API's stable error code (`error`), e.g. "missing_scope".
     */
    public function errorCode(): ?string
    {
        $error = $this->body->error ?? null;

        return is_string($error) ? $error : null;
    }

    /**
     * Seconds from the `Retry-After` header, null without one.
     */
    public function retryAfter(): ?int
    {
        $value = $this->response?->getHeaderLine('Retry-After');

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    public function header(string $name): ?string
    {
        $value = $this->response?->getHeaderLine($name);

        return $value === null || $value === '' ? null : $value;
    }
}
