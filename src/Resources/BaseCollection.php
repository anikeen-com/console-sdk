<?php

namespace Anikeen\Console\Resources;

use Anikeen\Console\AnikeenConsole;
use Anikeen\Console\Exceptions\ApiException;
use Anikeen\Console\Result;
use Anikeen\Id\Helpers\Paginator;
use ArrayIterator;
use Closure;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * A page of a list. Iterate it for the resources; nextPage() / previousPage() load the
 * neighbouring pages (Laravel's `?page=`), paginate() gives the raw Result with its Paginator.
 *
 * @template TResource of BaseResource
 *
 * @implements IteratorAggregate<int, TResource>
 */
class BaseCollection implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @var list<TResource>
     */
    protected array $items;

    /**
     * @param  class-string<TResource>  $resource
     * @param  Closure(?Paginator): Result  $fetch  loads a page
     */
    final public function __construct(
        public readonly Result $result,
        protected string $resource,
        protected Closure $fetch,
        protected ?AnikeenConsole $console = null,
    ) {
        $this->items = array_map(
            fn ($item) => $resource::make(is_object($item) || is_array($item) ? $item : ['value' => $item], $console),
            array_values(is_array($result->data) ? $result->data : []),
        );
    }

    /**
     * @param  class-string<TResource>  $resource
     * @param  Closure(?Paginator): Result  $fetch
     *
     * @throws ApiException
     */
    public static function load(string $resource, Closure $fetch, ?AnikeenConsole $console = null): static
    {
        return new static($fetch(null), $resource, $fetch, $console);
    }

    /**
     * @return list<TResource>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * @return TResource|null
     */
    public function first(): ?BaseResource
    {
        return $this->items[0] ?? null;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Items on all pages (`total`), else the count of this page.
     */
    public function total(): int
    {
        return (int) ($this->result->meta->total ?? $this->count());
    }

    public function currentPage(): int
    {
        return (int) ($this->result->meta->current_page ?? 1);
    }

    public function lastPage(): int
    {
        return (int) ($this->result->meta->last_page ?? $this->currentPage());
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage() < $this->lastPage();
    }

    /**
     * The next page, null on the last one.
     *
     * @throws ApiException
     */
    public function nextPage(): ?static
    {
        if (!$this->hasMorePages() || $this->result->paginator === null) {
            return null;
        }

        return new static(($this->fetch)((clone $this->result->paginator)->next()), $this->resource, $this->fetch, $this->console);
    }

    /**
     * The previous page, null on the first one.
     *
     * @throws ApiException
     */
    public function previousPage(): ?static
    {
        if ($this->currentPage() <= 1 || $this->result->paginator === null) {
            return null;
        }

        return new static(($this->fetch)((clone $this->result->paginator)->back()), $this->resource, $this->fetch, $this->console);
    }

    /**
     * The raw result (data, meta, links, paginator).
     */
    public function paginate(): Result
    {
        return $this->result;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function toArray(): array
    {
        return array_map(fn (BaseResource $item) => $item->toArray(), $this->items);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
