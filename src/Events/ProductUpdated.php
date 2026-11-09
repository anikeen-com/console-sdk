<?php

namespace Anikeen\Console\Events;

use Anikeen\Console\Resources\Product;

/**
 * `product.updated`: a product changed: suspended / resumed for billing, rescaled, reinstalled.
 *
 * @property-read Product|null $object
 */
class ProductUpdated extends WebhookEvent
{
}
