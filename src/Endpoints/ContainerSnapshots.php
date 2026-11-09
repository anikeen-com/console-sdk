<?php

namespace Anikeen\Console\Endpoints;

use Anikeen\Console\AnikeenConsole;
use Anikeen\Console\Exceptions\ApiException;
use Anikeen\Console\Resources\Accepted;
use Anikeen\Console\Resources\BaseCollection;
use Anikeen\Console\Resources\Snapshot;

/**
 * `v1/containers/{container}/snapshots`, addressed by name.
 */
class ContainerSnapshots
{
    public function __construct(
        protected AnikeenConsole $console,
        public readonly string $container,
    ) {
    }

    /**
     * @return BaseCollection<Snapshot>
     *
     * @throws ApiException
     */
    public function list(): BaseCollection
    {
        return BaseCollection::load(Snapshot::class, fn () => $this->console->call('GET', $this->path()), $this->console);
    }

    /**
     * @param  string  $name  a letter, then letters, digits, `-`, `_` (max 40), unique per container
     *
     * @throws ApiException
     */
    public function create(string $name, ?string $description = null): Accepted
    {
        return Accepted::fromResult($this->console->call('POST', $this->path(), ['name' => $name, 'description' => $description]), $this->console);
    }

    /**
     * Roll the container back (started again if it was running).
     *
     * @throws ApiException
     */
    public function rollback(string $name): Accepted
    {
        return Accepted::fromResult($this->console->call('POST', $this->path($name) . '/rollback', []), $this->console);
    }

    /**
     * @throws ApiException
     */
    public function delete(string $name): Accepted
    {
        return Accepted::fromResult($this->console->call('DELETE', $this->path($name)), $this->console);
    }

    protected function path(?string $name = null): string
    {
        $path = 'containers/' . rawurlencode($this->container) . '/snapshots';

        return $name === null ? $path : $path . '/' . rawurlencode($name);
    }
}
