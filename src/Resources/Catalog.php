<?php

namespace Anikeen\Console\Resources;

use Anikeen\Console\AnikeenConsole;
use Anikeen\Console\Result;
use stdClass;

/**
 * `GET v1/catalog`: the orderable families with their products; with a team also its prices,
 * VAT rate and the `options` per family (locations, templates, networks, SSH keys, …).
 *
 * @property list<stdClass> $data families: {family, name, products, options}
 * @property stdClass|null $team
 * @property float|int $vat_rate in percent (JSON may send 19 for 19.0, see vatRate())
 */
class Catalog extends BaseResource
{
    public static function fromResult(Result $result, ?AnikeenConsole $console = null): static
    {
        // the whole body: families in `data`, plus `team` and `vat_rate`
        $resource = static::make(is_object($result->body) ? $result->body : [], $console);
        $resource->result = $result;

        return $resource;
    }

    /**
     * The VAT rate in percent, e.g. 19.0.
     */
    public function vatRate(): float
    {
        return (float) ($this->vat_rate ?? 0);
    }

    /**
     * @return list<stdClass>
     */
    public function families(): array
    {
        return array_values((array) ($this->data ?? []));
    }

    /**
     * One family, e.g. "container"; null when it is not orderable with this token.
     */
    public function family(string $family): ?stdClass
    {
        foreach ($this->families() as $entry) {
            if (($entry->family ?? null) === $family) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The products of a family.
     *
     * @return list<stdClass>
     */
    public function products(string $family): array
    {
        return array_values((array) ($this->family($family)->products ?? []));
    }

    /**
     * The `options` of a family for the order attributes (only with a team).
     */
    public function options(string $family): ?stdClass
    {
        $options = $this->family($family)->options ?? null;

        return $options instanceof stdClass ? $options : null;
    }
}
