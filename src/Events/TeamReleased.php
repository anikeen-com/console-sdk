<?php

namespace Anikeen\Console\Events;

use Anikeen\Console\Resources\Team;

/**
 * `team.released`: the app lost access to the team; it is a normal team of the user now.
 *
 * @property-read Team|null $object
 */
class TeamReleased extends WebhookEvent
{
}
