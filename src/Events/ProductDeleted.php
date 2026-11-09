<?php

namespace Anikeen\Console\Events;

use Anikeen\Console\Resources\Product;

/**
 * `product.deleted`: a product was deleted.
 *
 * @property-read Product|null $object
 */
class ProductDeleted extends WebhookEvent
{
}
