<?php

namespace Anikeen\Console\Endpoints;

use Anikeen\Console\AnikeenConsole;
use Anikeen\Console\Exceptions\ApiException;
use Anikeen\Console\Resources\BaseCollection;
use Anikeen\Console\Resources\Order;
use Anikeen\Id\Helpers\Paginator;
use Illuminate\Support\Str;

/**
 * `v1/teams/{team}/orders`. For an app token the team must be the app team (appTeam()).
 */
class Orders
{
    protected ?string $idempotencyKey = null;

    public function __construct(
        protected AnikeenConsole $console,
        public readonly string $team,
    ) {
    }

    /**
     * Use your own Idempotency-Key for the next create() (e.g. one stored with your own order, so a
     * retry after a crash returns the first order instead of buying twice). Keys live 24 hours per
     * team; the same key with another body is refused (422 "idempotency_key_reused").
     */
    public function withIdempotencyKey(string $key): static
    {
        $orders = clone $this;
        $orders->idempotencyKey = $key;

        return $orders;
    }

    /**
     * Place an order. The response is `open` with a `checkout_url`: redirect the user there to
     * confirm (and pay) on Anikeen ID. Sent with an Idempotency-Key (a new UUID unless set with
     * withIdempotencyKey()); the one retry after a token refresh sends the same key.
     *
     * @param  list<array{family: string, product_id: string, attributes?: array<string, mixed>}>  $items
     *     1–10 items: `family` and `product_id` from the catalog, `attributes` per family
     *     (container: location_id, container_template_id, vlan_id, ssh_key?, name?,
     *     options?: array{features?: list<'fuse'|'keyctl'>, devices?: list<'tun'>} - LXC options
     *     for software that runs its own containers inside, e.g. rootless Podman; nesting is
     *     always on, anything else answers 422)
     * @param  array{tos_accepted?: bool, withdrawal_waiver?: bool, coupon_code?: string, return_url?: string}  $options
     *     `tos_accepted` is required by the Console and must be true: only send it once the user
     *     accepted the terms of service. `return_url` (app tokens only): where the user lands after
     *     the checkout on Anikeen ID, with `console_order=<order id>&status=<status>` appended
     *     (https; plain http only for localhost). Read the order before trusting it.
     *
     * @throws ApiException 422 ValidationFailedException, 429 RateLimitException, 403 MissingScopeException, …
     */
    public function create(array $items, array $options = []): Order
    {
        $key = $this->idempotencyKey ?? (string) Str::uuid();

        $result = $this->console->call('POST', $this->path(), ['items' => array_values($items)] + $options, headers: ['Idempotency-Key' => $key]);

        $order = Order::fromResult($result, $this->console);
        $order->idempotencyKey = $key;

        return $order;
    }

    /**
     * The team's orders, newest first.
     *
     * @param  array{status?: string, per_page?: int, page?: int}  $query
     *
     * @return BaseCollection<Order>
     *
     * @throws ApiException
     */
    public function list(array $query = []): BaseCollection
    {
        return BaseCollection::load(
            Order::class,
            fn (?Paginator $paginator) => $this->console->call('GET', $this->path(), query: $paginator ? ['page' => $paginator->cursor()] + $query : $query),
            $this->console,
        );
    }

    /**
     * One order with its items; an open one is checked with Anikeen ID first.
     *
     * @throws ApiException 404 NotFoundException
     */
    public function find(string $id): Order
    {
        return Order::fromResult($this->console->call('GET', $this->path() . '/' . rawurlencode($id)), $this->console);
    }

    protected function path(): string
    {
        return 'teams/' . rawurlencode($this->team) . '/orders';
    }
}
