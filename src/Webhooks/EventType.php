<?php

namespace Anikeen\Console\Webhooks;

/**
 * The `type` of webhook events the Anikeen Console sends to apps, e.g.
 * `match ($event->type) { EventType::PRODUCT_READY => …, … }`. Endpoints opt in per type.
 * `data.object`: the order as in `GET v1/teams/{team}/orders/{order}` for `order.*`,
 * `{id, family, team_id, status, …}` for `product.*`.
 */
final class EventType
{
    /** Checkout confirmed on Anikeen ID, provisioning started. */
    public const ORDER_COMPLETED = 'order.completed';

    /** Checkout cancelled or expired, or provisioning refused. */
    public const ORDER_FAILED = 'order.failed';

    /** A product was built (a container: running, addresses known). */
    public const PRODUCT_READY = 'product.ready';

    /** Building a product failed (revoked and refunded). */
    public const PRODUCT_FAILED = 'product.failed';

    /** A product's status changed: suspended for billing / resumed, rescaled, reinstalled. */
    public const PRODUCT_UPDATED = 'product.updated';

    /** A product was deleted (by the app, its contract ended in Anikeen ID, or purged). */
    public const PRODUCT_DELETED = 'product.deleted';

    /** The app lost access to the user's team (the user removed it in Anikeen ID); the last event for that team. */
    public const TEAM_RELEASED = 'team.released';

    /** Sent by the test button in the Console. */
    public const WEBHOOK_TEST = 'webhook.test';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }
}
