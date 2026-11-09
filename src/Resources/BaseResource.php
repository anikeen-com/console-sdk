<?php

namespace Anikeen\Console\Resources;

use Anikeen\Console\AnikeenConsole;
use Anikeen\Console\Result;
use JsonSerializable;
use LogicException;

/**
 * One object of the Console API. Its fields are public properties (nested objects as stdClass,
 * like anikeen/id); unknown fields read as null. Resources from webhooks have no client: bind one
 * with withClient() before calling actions on them.
 */
#[\AllowDynamicProperties]
abstract class BaseResource implements JsonSerializable
{
    /**
     * The response this resource was read from, null when built from a webhook or a list.
     */
    public ?Result $result = null;

    protected ?AnikeenConsole $console = null;

    /**
     * @var array<string, mixed>
     */
    private array $attributes = [];

    final public function __construct(array|object $attributes = [], ?AnikeenConsole $console = null)
    {
        $this->console = $console;
        $this->fill($attributes);
    }

    public static function fromResult(Result $result, ?AnikeenConsole $console = null): static
    {
        $resource = static::make(is_array($result->data) || is_object($result->data) ? $result->data : [], $console);
        $resource->result = $result;

        return $resource;
    }

    /**
     * Build the resource from decoded data (an API response or a webhook's `object`).
     */
    public static function make(array|object $attributes, ?AnikeenConsole $console = null): static
    {
        return new static($attributes, $console);
    }

    /**
     * Use $console for this resource's actions (e.g. one from a webhook):
     * `$event->object->withClient(AnikeenConsole::forUser($user))->power('start')`.
     */
    public function withClient(AnikeenConsole $console): static
    {
        $resource = clone $this;
        $resource->console = $console;

        return $resource;
    }

    protected function fill(array|object $attributes): void
    {
        // objects all the way down, as json_decode(…, false) gives them
        $attributes = json_decode(json_encode($attributes, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), false, 512, JSON_THROW_ON_ERROR);

        $this->attributes = [];

        foreach ((array) $attributes as $key => $value) {
            $this->attributes[$key] = $value;

            if (!property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    protected function client(): AnikeenConsole
    {
        return $this->console ?? throw new LogicException(sprintf(
            '%s has no API client; call withClient(AnikeenConsole::forUser($user)) first.',
            static::class,
        ));
    }

    /**
     * The fields as an array (nested objects too).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return json_decode(json_encode($this->attributes, JSON_PRESERVE_ZERO_FRACTION), true) ?? [];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __get(string $name): mixed
    {
        return null;
    }

    public function __isset(string $name): bool
    {
        return false;
    }

    public function __serialize(): array
    {
        // the client (Guzzle, the user) is not serialized: queued listeners bind one again
        return ['attributes' => $this->attributes];
    }

    public function __unserialize(array $data): void
    {
        $this->fill($data['attributes'] ?? []);
    }
}
