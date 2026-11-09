<?php

namespace Anikeen\Console\Events;

use Anikeen\Console\Resources\Order;

/**
 * `order.failed`: the checkout was cancelled or expired, or provisioning was refused.
 *
 * @property-read Order|null $object
 */
class OrderFailed extends WebhookEvent
{
}
