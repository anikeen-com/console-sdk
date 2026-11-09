<?php

namespace Anikeen\Console\Tests;

use Anikeen\Console\Events\WebhookReceived;
use Anikeen\Console\Facades\AnikeenConsole;
use Anikeen\Console\Webhooks\EventType;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/**
 * The webhook route registered in routes/web.php (the web middleware group, with Laravel's CSRF /
 * request forgery check) must take the Console's POSTs: they carry no CSRF token, only a signature.
 */
class WebhookWebGroupTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // the web group encrypts cookies
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(fn () => Route::anikeenConsoleWebhooks('webhooks/anikeen-console'));
    }

    public function test_a_signed_webhook_passes_the_web_group(): void
    {
        Event::fake([WebhookReceived::class]);

        AnikeenConsole::webhookRequest(EventType::WEBHOOK_TEST, ['message' => 'hello'])->send()->assertOk();

        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event) => $event->type === EventType::WEBHOOK_TEST);
    }

    public function test_an_unsigned_post_is_still_refused(): void
    {
        $this->postJson('/webhooks/anikeen-console', ['type' => EventType::WEBHOOK_TEST])->assertStatus(400);
    }
}
