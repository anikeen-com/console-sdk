<?php

namespace Anikeen\Console\Tests;

use Anikeen\Console\Events\OrderCompleted;
use Anikeen\Console\Events\ProductReady;
use Anikeen\Console\Events\TeamReleased;
use Anikeen\Console\Events\WebhookEvent;
use Anikeen\Console\Events\WebhookReceived;
use Anikeen\Console\Events\WebhookTest as WebhookTestEvent;
use Anikeen\Console\Facades\AnikeenConsole;
use Anikeen\Console\Http\Controllers\WebhookController;
use Anikeen\Console\Resources\Container;
use Anikeen\Console\Resources\Order;
use Anikeen\Console\Resources\Product;
use Anikeen\Console\Resources\Team;
use Anikeen\Console\Webhooks\EventType;
use Anikeen\Id\Webhooks\Webhook;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use RuntimeException;

class WebhookTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::anikeenConsoleWebhooks('webhooks/anikeen-console');
    }

    public function test_the_event_types_of_the_console(): void
    {
        $this->assertSame([
            'order.completed', 'order.failed', 'product.ready', 'product.failed',
            'product.updated', 'product.deleted', 'team.released', 'webhook.test',
        ], EventType::all());
        $this->assertSame(EventType::all(), array_keys(WebhookController::EVENTS));
    }

    public function test_the_request_is_signed_like_the_console(): void
    {
        $request = AnikeenConsole::webhookRequest(EventType::PRODUCT_READY, ['id' => '01JCT', 'family' => 'container']);

        $event = Webhook::constructEvent($request->body, $request->headers['Anikeen-Signature'], 'whsec_test');

        $this->assertSame('product.ready', $event['type']);
        $this->assertSame($event['id'], $request->headers['Anikeen-Event-Id']);
        $this->assertSame('product.ready', $request->headers['Anikeen-Event-Type']);
        $this->assertSame('01JCT', $event['data']['object']['id']);
    }

    public function test_a_signed_webhook_dispatches_the_events(): void
    {
        Event::fake();

        AnikeenConsole::webhookRequest(EventType::PRODUCT_READY, ['id' => '01JCT', 'family' => 'container', 'team_id' => 'team_1', 'status' => 'running', 'name' => 'ct-1'])
            ->send()
            ->assertOk()
            ->assertJson(['received' => true]);

        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event) => $event->type === 'product.ready');
        Event::assertDispatched(ProductReady::class, function (ProductReady $event) {
            $this->assertInstanceOf(Container::class, $event->object);
            $this->assertSame('ct-1', $event->object->name);
            $this->assertSame('team_1', $event->data['object']['team_id']);
            $this->assertIsInt($event->created);

            return true;
        });
    }

    public function test_objects_become_resources(): void
    {
        $order = OrderCompleted::fromEnvelope(['id' => 'evt_1', 'type' => 'order.completed', 'data' => ['object' => ['id' => '01JORD', 'status' => 'completed']]]);
        $product = ProductReady::fromEnvelope(['id' => 'evt_2', 'type' => 'product.ready', 'data' => ['object' => ['id' => '01JSRV', 'family' => 'server']]]);
        $team = TeamReleased::fromEnvelope(['id' => 'evt_3', 'type' => 'team.released', 'data' => ['object' => ['id' => 'team_1', 'name' => 'Gleem']]]);
        $test = WebhookTestEvent::fromEnvelope(['id' => 'evt_4', 'type' => 'webhook.test', 'data' => ['object' => ['hello' => 'world']]]);

        $this->assertInstanceOf(Order::class, $order->object);
        $this->assertTrue($order->object->isCompleted());
        $this->assertSame(Product::class, get_class($product->object));
        $this->assertInstanceOf(Team::class, $team->object);
        $this->assertNull($test->object);
        $this->assertSame('world', $test->data['object']['hello']);
    }

    public function test_events_survive_serialization_for_queued_listeners(): void
    {
        $event = ProductReady::fromEnvelope(['id' => 'evt_1', 'type' => 'product.ready', 'data' => ['object' => ['id' => '01JCT', 'family' => 'container', 'location' => ['id' => 'bon1']]]]);
        $event->object->withClient(app(\Anikeen\Console\AnikeenConsole::class)->withToken('x'));

        $copy = unserialize(serialize($event));

        $this->assertInstanceOf(Container::class, $copy->object);
        $this->assertSame('bon1', $copy->object->location->id);
    }

    public function test_an_unknown_type_is_still_received(): void
    {
        Event::fake();

        AnikeenConsole::webhookRequest('product.rescaled', ['id' => '01JCT'])->send()->assertOk();

        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event) => $event->type === 'product.rescaled' && $event->object instanceof Product);
        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        Event::assertNotDispatched(ProductReady::class);
    }

    public function test_a_bad_signature_is_refused(): void
    {
        Event::fake([WebhookReceived::class, OrderCompleted::class]);

        AnikeenConsole::webhookRequest(EventType::ORDER_COMPLETED, ['id' => '01JORD'], secret: 'whsec_other')->send()->assertStatus(400);
        AnikeenConsole::webhookRequest(EventType::ORDER_COMPLETED, ['id' => '01JORD'])->withHeaders(['Anikeen-Signature' => 'garbage'])->send()->assertStatus(400);

        Event::assertNothingDispatched();
    }

    public function test_an_old_request_is_refused(): void
    {
        Event::fake([WebhookReceived::class, OrderCompleted::class]);
        $request = \Anikeen\Console\Testing\WebhookRequest::make(EventType::ORDER_COMPLETED, ['id' => '01JORD'], [], 'whsec_test', timestamp: time() - 3600);

        $request->send()->assertStatus(400);

        Event::assertNothingDispatched();
    }

    public function test_a_replayed_event_is_ignored(): void
    {
        Event::fake();
        $request = AnikeenConsole::webhookRequest(EventType::ORDER_COMPLETED, ['id' => '01JORD']);

        $request->send()->assertOk();
        $request->send()->assertOk()->assertJson(['duplicate' => true]);

        Event::assertDispatchedTimes(OrderCompleted::class, 1);
        Event::assertDispatchedTimes(WebhookReceived::class, 1);
    }

    public function test_a_failed_listener_lets_the_retry_through(): void
    {
        $calls = 0;
        Event::listen(OrderCompleted::class, function () use (&$calls) {
            if (++$calls === 1) {
                throw new RuntimeException('database down');
            }
        });
        $request = AnikeenConsole::webhookRequest(EventType::ORDER_COMPLETED, ['id' => '01JORD']);

        $request->send()->assertStatus(500);
        $request->send()->assertOk()->assertJsonMissing(['duplicate' => true]);

        $this->assertSame(2, $calls);
    }

    public function test_a_missing_secret_fails_loudly(): void
    {
        $request = AnikeenConsole::webhookRequest(EventType::WEBHOOK_TEST, []);
        config(['services.anikeen_console.webhook_secret' => null]);

        $request->send()->assertStatus(500);
    }

    public function test_the_route_skips_csrf(): void
    {
        $route = Route::getRoutes()->getByName('anikeen-console.webhooks');

        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('webhooks/anikeen-console', $route->uri());
        $this->assertContains(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, $route->excludedMiddleware());
        $this->assertSame('/webhooks/anikeen-console', \Anikeen\Console\AnikeenConsole::webhookUri());
    }

    public function test_the_typed_events_share_one_base(): void
    {
        foreach (WebhookController::EVENTS as $class) {
            $this->assertTrue(is_subclass_of($class, WebhookEvent::class));
        }
    }
}
