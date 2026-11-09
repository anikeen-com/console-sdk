<?php

namespace Anikeen\Console\Testing;

use Anikeen\Console\AnikeenConsole;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert as PHPUnit;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * AnikeenConsole::fake(): answers requests with the given responses (or defaults) and records
 * them. Tokens are not needed and never refreshed.
 *
 * Defaults without a matching response: `GET app-team` a team "App" (id "team_fake"),
 * `POST teams/{team}/orders` an open order with a checkout_url, `GET containers`, backups and
 * snapshots an empty list, other `POST` 202, everything else 200 `{}`.
 */
class AnikeenConsoleFake extends AnikeenConsole
{
    /**
     * @var list<RecordedRequest>
     */
    protected array $recorded = [];

    /**
     * @param  array<string, mixed>  $responses
     */
    public function __construct(protected array $responses = [])
    {
        parent::__construct();

        $this->client = new Client(['handler' => HandlerStack::create(fn (RequestInterface $request) => Create::promiseFor($this->handle($request)))]);
    }

    public function forUser(object $user): static
    {
        $console = parent::forUser($user);
        $console->token = $console->storedAccessToken() ?? 'fake-token';

        return $console;
    }

    /**
     * Add or replace fake responses.
     *
     * @param  array<string, mixed>  $responses
     */
    public function respondWith(array $responses): static
    {
        $this->responses = array_merge($this->responses, $responses);

        return $this;
    }

    /**
     * @return list<RecordedRequest>
     */
    public function recorded(?Closure $filter = null): array
    {
        return $filter ? array_values(array_filter($this->recorded, $filter)) : $this->recorded;
    }

    public function assertSent(Closure|string $match): void
    {
        PHPUnit::assertNotEmpty($this->matching($match), 'The expected Anikeen Console request was not sent.');
    }

    public function assertNotSent(Closure|string $match): void
    {
        PHPUnit::assertEmpty($this->matching($match), 'An unexpected Anikeen Console request was sent.');
    }

    public function assertNothingSent(): void
    {
        PHPUnit::assertEmpty($this->recorded, 'Requests were sent to the Anikeen Console.');
    }

    /**
     * An order was placed; $callback (optional) gets the items, the whole body, the team id and
     * the RecordedRequest and returns whether it is the expected one.
     *
     * @param  (Closure(array, array, string, RecordedRequest): bool)|null  $callback
     */
    public function assertOrdered(?Closure $callback = null): void
    {
        $orders = array_filter($this->placedOrders(), fn (RecordedRequest $request) => $callback === null
            || $callback($request->json['items'] ?? [], $request->json, $this->teamOf($request), $request));

        PHPUnit::assertNotEmpty($orders, $callback ? 'No matching order was placed in the Anikeen Console.' : 'No order was placed in the Anikeen Console.');
    }

    public function assertNothingOrdered(): void
    {
        PHPUnit::assertEmpty($this->placedOrders(), 'An order was placed in the Anikeen Console.');
    }

    /**
     * @return list<RecordedRequest>
     */
    protected function placedOrders(): array
    {
        return $this->recorded(fn (RecordedRequest $request) => $request->matches('POST teams/*/orders'));
    }

    protected function teamOf(RecordedRequest $request): string
    {
        return rawurldecode(explode('/', $request->path)[1] ?? '');
    }

    /**
     * @return list<RecordedRequest>
     */
    protected function matching(Closure|string $match): array
    {
        return $this->recorded(is_string($match) ? fn (RecordedRequest $request) => $request->matches($match) : $match);
    }

    protected function handle(RequestInterface $request): ResponseInterface
    {
        $base = parse_url($this->getBaseUrl(), PHP_URL_PATH) ?: '';
        $path = trim(substr($request->getUri()->getPath(), strlen(rtrim($base, '/'))), '/');
        parse_str($request->getUri()->getQuery(), $query);
        $json = json_decode((string) $request->getBody(), true);

        $recorded = new RecordedRequest($request->getMethod(), $path, $query, is_array($json) ? $json : [], $request);
        $this->recorded[] = $recorded;

        foreach ($this->responses as $pattern => $response) {
            if ($recorded->matches((string) $pattern)) {
                return $this->toResponse($response instanceof Closure ? $response($recorded) : $response, $recorded);
            }
        }

        return $this->toResponse($this->defaultResponse($recorded), $recorded);
    }

    protected function toResponse(mixed $response, RecordedRequest $request): ResponseInterface
    {
        if ($response instanceof ResponseInterface) {
            return $response;
        }

        $status = $request->method === 'POST' && $request->matches('teams/*/orders') ? 201 : 200;

        return static::response($response ?? [], $status);
    }

    /**
     * A JSON response for fake(), e.g. `AnikeenConsoleFake::response(['message' => '…', 'error' => 'missing_scope'], 403)`.
     *
     * @param  array<mixed>|object  $body
     * @param  array<string, string>  $headers
     */
    public static function response(array|object $body = [], int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body === [] ? (object) [] : $body));
    }

    protected function defaultResponse(RecordedRequest $request): Response
    {
        return match (true) {
            $request->matches('GET app-team') => static::response(['data' => ['id' => 'team_fake', 'name' => 'App']]),
            $request->matches('POST teams/*/orders') => static::response(['data' => $this->fakeOrder($request)], 201),
            $request->matches('GET containers') => static::response(['current_page' => 1, 'data' => [], 'last_page' => 1, 'per_page' => 15, 'total' => 0]),
            $request->matches('GET containers/*/backups'), $request->matches('GET containers/*/snapshots') => static::response(['data' => []]),
            $request->matches('GET catalog') => static::response(['data' => [], 'team' => null, 'vat_rate' => 19.0]),
            $request->method === 'DELETE' && $request->matches('containers/*') && substr_count($request->path, '/') === 1 => new Response(204),
            $request->method === 'POST' => static::response(['message' => 'Accepted.'], 202),
            default => static::response(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function fakeOrder(RecordedRequest $request): array
    {
        $id = (string) Str::ulid();

        return [
            'id' => $id,
            'team_id' => $this->teamOf($request),
            'status' => 'open',
            'mode' => 'session',
            'source' => 'api',
            'checkout_url' => 'https://id.anikeen.com/checkout/sessions/fake-' . strtolower($id),
            'expires_at' => now()->addDay()->toIso8601String(),
            'error' => null,
            'items' => array_map(fn (array $item) => [
                'id' => (string) Str::ulid(),
                'family' => $item['family'] ?? null,
                'product_id' => $item['product_id'] ?? null,
                'product_name' => null,
                'unit' => null,
                'list_price' => null,
                'price' => null,
                'status' => 'pending',
                'product' => null,
                'provisioned_at' => null,
            ], array_values(array_filter($request->json['items'] ?? [], 'is_array'))),
            'created_at' => now()->toIso8601String(),
            'completed_at' => null,
        ];
    }
}
