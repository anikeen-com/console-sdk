<?php

namespace Anikeen\Console\Events;

use Anikeen\Console\Resources\Product;

/**
 * `product.failed`: building a product failed (revoked and refunded).
 *
 * @property-read Product|null $object
 */
class ProductFailed extends WebhookEvent
{
}
