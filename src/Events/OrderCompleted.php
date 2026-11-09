<?php

namespace Anikeen\Console\Events;

use Anikeen\Console\Resources\Order;

/**
 * `order.completed`: the checkout was confirmed on Anikeen ID, provisioning started.
 *
 * @property-read Order|null $object
 */
class OrderCompleted extends WebhookEvent
{
}
