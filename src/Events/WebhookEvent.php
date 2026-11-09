<?php

namespace Anikeen\Console\Events;

use Anikeen\Console\Resources\BaseResource;
use Anikeen\Console\Resources\Order;
use Anikeen\Console\Resources\Product;
use Anikeen\Console\Resources\Team;
use Anikeen\Console\Webhooks\EventType;

/**
 * A verified webhook of the Anikeen Console. `object` is `data.object` as a resource (Order for
 * `order.*`, Product / Container for `product.*`, Team for `team.released`), `data` the whole
 * `data` as an array. Listeners may be queued.
 */
abstract class WebhookEvent
{
    public readonly ?BaseResource $object;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?int $created,
        public readonly array $data,
    ) {
        $this->object = static::resource($type, $data['object'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $event  the verified envelope {id, type, created, data}
     */
    public static function fromEnvelope(array $event): static
    {
        return new static(
            (string) $event['id'],
            (string) $event['type'],
            isset($event['created']) && is_numeric($event['created']) ? (int) $event['created'] : null,
            is_array($event['data'] ?? null) ? $event['data'] : [],
        );
    }

    protected static function resource(string $type, mixed $object): ?BaseResource
    {
        if (!is_array($object)) {
            return null;
        }

        return match (true) {
            str_starts_with($type, 'order.') => Order::make($object),
            str_starts_with($type, 'product.') => Product::make($object),
            $type === EventType::TEAM_RELEASED => Team::make($object),
            default => null,
        };
    }
}
