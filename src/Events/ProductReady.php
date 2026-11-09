<?php

namespace Anikeen\Console\Events;

use Anikeen\Console\Resources\Product;

/**
 * `product.ready`: a product was built (a container: running, addresses known).
 *
 * @property-read Product|null $object
 */
class ProductReady extends WebhookEvent
{
}
