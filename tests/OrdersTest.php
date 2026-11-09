<?php

namespace Anikeen\Console\Tests;

use Anikeen\Console\Facades\AnikeenConsole;
use Anikeen\Console\Resources\Order;
use Anikeen\Console\Resources\Team;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;

class OrdersTest extends TestCase
{
    private function order(array $overrides = []): array
    {
        return ['data' => $overrides + [
            'id' => '01JORD', 'team_id' => 'team_1', 'status' => 'open', 'mode' => 'session', 'source' => 'api',
            'checkout_url' => 'https://id.anikeen.com/checkout/sessions/abc', 'expires_at' => '2026-11-10T12:00:00+00:00',
            'error' => null,
            'items' => [['id' => '01JITM', 'family' => 'container', 'product_id' => '01JPLN', 'status' => 'pending', 'product' => null]],
            'created_at' => '2026-11-09T12:00:00+00:00', 'completed_at' => null,
        ]];
    }

    private array $items = [['family' => 'container', 'product_id' => '01JPLN', 'attributes' => ['location_id' => 'bon1', 'container_template_id' => '01JTPL', 'vlan_id' => '01JVLN']]];

    public function test_an_order_is_placed_with_an_idempotency_key(): void
    {
        $this->respond(201, $this->order());

        $order = $this->console()->orders('team_1')->create($this->items, ['tos_accepted' => true, 'withdrawal_waiver' => true]);

        $this->assertRequest(0, 'POST', 'teams/team_1/orders', ['items' => $this->items, 'tos_accepted' => true, 'withdrawal_waiver' => true]);
        $key = $this->request(0)->getHeaderLine('Idempotency-Key');
        $this->assertTrue(Str::isUuid($key));
        $this->assertSame($key, $order->idempotencyKey);
        $this->assertSame('application/json', $this->request(0)->getHeaderLine('Content-Type'));

        $this->assertInstanceOf(Order::class, $order);
        $this->assertTrue($order->isOpen());
        $this->assertSame('https://id.anikeen.com/checkout/sessions/abc', $order->checkout_url);
        $this->assertSame('container', $order->items[0]->family);
    }

    public function test_an_order_with_a_return_url(): void
    {
        $this->respond(201, $this->order());

        $this->console()->orders('team_1')->create($this->items, ['tos_accepted' => true, 'return_url' => 'https://gleem.example/ordered']);

        $this->assertRequest(0, 'POST', 'teams/team_1/orders', ['items' => $this->items, 'tos_accepted' => true, 'return_url' => 'https://gleem.example/ordered']);
    }

    public function test_every_order_gets_a_new_key(): void
    {
        $this->respond(201, $this->order());
        $this->respond(201, $this->order());
        $orders = $this->console()->orders('team_1');

        $orders->create($this->items, ['tos_accepted' => true]);
        $orders->create($this->items, ['tos_accepted' => true]);

        $this->assertNotSame($this->request(0)->getHeaderLine('Idempotency-Key'), $this->request(1)->getHeaderLine('Idempotency-Key'));
    }

    public function test_an_own_idempotency_key(): void
    {
        $this->respond(201, $this->order());

        $this->console()->orders('team_1')->withIdempotencyKey('gleem-order-17')->create($this->items, ['tos_accepted' => true]);

        $this->assertSame('gleem-order-17', $this->request(0)->getHeaderLine('Idempotency-Key'));
    }

    public function test_the_retry_after_a_refresh_sends_the_same_key(): void
    {
        $user = $this->user(self::jwt());
        $this->respond(401, ['message' => 'Unauthenticated.']);
        $this->idHttp->append(new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => self::jwt(jti: 'b'), 'refresh_token' => 'refresh-2'])));
        $this->respond(201, $this->order());

        AnikeenConsole::forUser($user)->orders('team_1')->create($this->items, ['tos_accepted' => true]);

        $this->assertCount(2, $this->sent);
        $this->assertSame($this->request(0)->getHeaderLine('Idempotency-Key'), $this->request(1)->getHeaderLine('Idempotency-Key'));
        $this->assertSame($this->sentJson(0), $this->sentJson(1));
    }

    public function test_a_team_resource_can_be_passed(): void
    {
        $this->respond(201, $this->order());

        $this->console()->orders(Team::make(['id' => 'team 1', 'name' => 'Gleem']))->create($this->items, ['tos_accepted' => true]);

        $this->assertRequest(0, 'POST', 'teams/team%201/orders');
    }

    public function test_orders_are_listed_page_by_page(): void
    {
        $page = fn (int $current) => [
            'data' => [$this->order(['id' => "01JORD{$current}"])['data']],
            'links' => ['first' => 'https://console.test/v1/teams/team_1/orders?page=1', 'last' => 'https://console.test/v1/teams/team_1/orders?page=2', 'prev' => $current > 1 ? 'https://console.test/v1/teams/team_1/orders?page=1' : null, 'next' => $current < 2 ? 'https://console.test/v1/teams/team_1/orders?page=2' : null],
            'meta' => ['current_page' => $current, 'last_page' => 2, 'per_page' => 1, 'total' => 2],
        ];
        $this->respond(200, $page(1));
        $this->respond(200, $page(2));

        $orders = $this->console()->orders('team_1')->list(['status' => 'open', 'per_page' => 1]);

        $this->assertRequest(0, 'GET', 'teams/team_1/orders', query: ['status' => 'open', 'per_page' => '1']);
        $this->assertCount(1, $orders);
        $this->assertSame(2, $orders->total());
        $this->assertTrue($orders->hasMorePages());
        $this->assertInstanceOf(Order::class, $orders->first());
        $this->assertSame('01JORD1', $orders->first()->id);

        $next = $orders->nextPage();

        $this->assertRequest(1, 'GET', 'teams/team_1/orders', query: ['page' => '2', 'status' => 'open', 'per_page' => '1']);
        $this->assertSame('01JORD2', $next->first()->id);
        $this->assertFalse($next->hasMorePages());
        $this->assertNull($next->nextPage());
        $this->assertCount(2, $this->sent);
    }

    public function test_an_order_is_found_and_refreshed(): void
    {
        $this->respond(200, $this->order());
        $this->respond(200, $this->order(['status' => 'completed', 'checkout_url' => null, 'items' => [['id' => '01JITM', 'family' => 'container', 'status' => 'provisioned', 'product' => ['type' => 'container', 'id' => '01JCT']]]]));

        $order = $this->console()->orders('team_1')->find('01JORD');
        $order = $order->refresh();

        $this->assertRequest(0, 'GET', 'teams/team_1/orders/01JORD');
        $this->assertRequest(1, 'GET', 'teams/team_1/orders/01JORD');
        $this->assertTrue($order->isCompleted());
        $this->assertTrue($order->isFinal());
        $this->assertSame('01JCT', $order->products()[0]->id);
        $this->assertSame('completed', $order->toArray()['status']);
    }
}
