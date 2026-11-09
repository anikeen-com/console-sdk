<?php

namespace Anikeen\Console\Testing;

use Psr\Http\Message\RequestInterface;

/**
 * A request the fake received.
 */
class RecordedRequest
{
    /**
     * @param  string  $path  relative to the base URL, without the query (e.g. "teams/01JA/orders")
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $json  the decoded JSON body
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $json,
        public readonly RequestInterface $request,
    ) {
    }

    public function header(string $name): ?string
    {
        $value = $this->request->getHeaderLine($name);

        return $value === '' ? null : $value;
    }

    /**
     * Whether method and path match a pattern like `GET containers/*` (method optional,
     * `*` matches anything, also slashes).
     */
    public function matches(string $pattern): bool
    {
        if (preg_match('/^(GET|POST|PUT|PATCH|DELETE)\s+(.+)$/i', trim($pattern), $match)) {
            if (strtoupper($match[1]) !== $this->method) {
                return false;
            }
            $pattern = $match[2];
        }

        $pattern = trim($pattern, '/');
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';

        return (bool) preg_match($regex, $this->path);
    }
}
