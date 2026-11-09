<?php

namespace Anikeen\Console\Http\Controllers;

use Anikeen\Console\Events;
use Anikeen\Console\Webhooks\EventType;
use Anikeen\Id\Exceptions\SignatureVerificationException;
use Anikeen\Id\Webhooks\Webhook;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Receives the Console's webhooks (Route::anikeenConsoleWebhooks()): verifies the signature with
 * `services.anikeen_console.webhook_secret`, drops events already handled (by event id, 3 days)
 * and dispatches WebhookReceived plus the typed event.
 */
class WebhookController
{
    /**
     * How long a handled event id is remembered (the Console retries for about 2 days).
     */
    public const REPLAY_TTL = 3 * 24 * 60 * 60;

    /**
     * @var array<string, class-string<Events\WebhookEvent>>
     */
    public const EVENTS = [
        EventType::ORDER_COMPLETED => Events\OrderCompleted::class,
        EventType::ORDER_FAILED => Events\OrderFailed::class,
        EventType::PRODUCT_READY => Events\ProductReady::class,
        EventType::PRODUCT_FAILED => Events\ProductFailed::class,
        EventType::PRODUCT_UPDATED => Events\ProductUpdated::class,
        EventType::PRODUCT_DELETED => Events\ProductDeleted::class,
        EventType::TEAM_RELEASED => Events\TeamReleased::class,
        EventType::WEBHOOK_TEST => Events\WebhookTest::class,
    ];

    public function __invoke(Request $request, Dispatcher $events): JsonResponse
    {
        $secret = (string) config('services.anikeen_console.webhook_secret');

        if ($secret === '') {
            throw new RuntimeException('Set services.anikeen_console.webhook_secret (ANIKEEN_CONSOLE_WEBHOOK_SECRET) to receive Anikeen Console webhooks.');
        }

        try {
            $event = Webhook::constructEvent($request->getContent(), $request->header('Anikeen-Signature'), $secret);
        } catch (SignatureVerificationException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 400);
        }

        // the signed envelope's id (the Anikeen-Event-Id header carries the same, unsigned)
        $key = 'anikeen-console:webhook:' . sha1((string) $event['id']);

        if (!Cache::add($key, true, self::REPLAY_TTL)) {
            return new JsonResponse(['received' => true, 'duplicate' => true]);
        }

        try {
            $events->dispatch(Events\WebhookReceived::fromEnvelope($event));

            if ($class = self::EVENTS[$event['type']] ?? null) {
                $events->dispatch($class::fromEnvelope($event));
            }
        } catch (Throwable $exception) {
            // not handled: let the Console's retry through
            Cache::forget($key);

            throw $exception;
        }

        return new JsonResponse(['received' => true]);
    }
}
