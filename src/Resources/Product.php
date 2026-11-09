<?php

namespace Anikeen\Console\Resources;

use Anikeen\Console\AnikeenConsole;

/**
 * A product as sent in `product.*` webhooks: `{id, family, team_id, status, …}` plus the family's
 * attributes as in the API. make() returns the family's class when the SDK has one (Container).
 *
 * @property string $id
 * @property string|null $family
 * @property string|null $team_id
 * @property string|null $status
 */
class Product extends BaseResource
{
    /**
     * @var array<string, class-string<Product>>
     */
    public const FAMILIES = [
        'container' => Container::class,
    ];

    public static function make(array|object $attributes, ?AnikeenConsole $console = null): static
    {
        $family = is_array($attributes) ? ($attributes['family'] ?? null) : ($attributes->family ?? null);
        $class = static::class === self::class && is_string($family) ? (self::FAMILIES[$family] ?? static::class) : static::class;

        return new $class($attributes, $console);
    }
}
