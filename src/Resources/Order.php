<?php

namespace Anikeen\Console\Resources;

use Anikeen\Console\Exceptions\ApiException;
use stdClass;

/**
 * An order (a Console checkout). An app's order is `open` with a `checkout_url` the user confirms
 * on Anikeen ID; it turns `completed` once confirmed (webhook `order.completed`), else `cancelled`,
 * `expired` or `failed`. Items are `pending` until built, then `provisioned` with `product`.
 *
 * @property string $id
 * @property string $team_id
 * @property string $status pending, open, completed, cancelled, expired, failed
 * @property string $mode session or direct
 * @property string $source api or console
 * @property string|null $checkout_url shown only to the user who ordered, while open
 * @property string|null $expires_at
 * @property string|null $error
 * @property list<stdClass> $items {id, family, product_id, product_name, unit, list_price, price, status, product: {type, id}|null, provisioned_at}
 * @property string|null $created_at
 * @property string|null $completed_at
 */
class Order extends BaseResource
{
    /**
     * The Idempotency-Key create() sent; store it to retry the same order safely.
     */
    public ?string $idempotencyKey = null;

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * The order will not change any more (completed, cancelled, expired or failed).
     */
    public function isFinal(): bool
    {
        return in_array($this->status, ['completed', 'cancelled', 'expired', 'failed'], true);
    }

    /**
     * The built products: `{type, id}` per provisioned item, e.g. `{type: "container", id: "01JD…"}`.
     *
     * @return list<stdClass>
     */
    public function products(): array
    {
        return array_values(array_filter(array_map(fn ($item) => $item->product ?? null, (array) ($this->items ?? []))));
    }

    /**
     * Read the order again (the Console asks Anikeen ID for the state of an open order).
     *
     * @throws ApiException
     */
    public function refresh(): static
    {
        return $this->client()->orders($this->team_id)->find($this->id);
    }
}
