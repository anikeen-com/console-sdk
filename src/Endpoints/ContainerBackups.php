<?php

namespace Anikeen\Console\Endpoints;

use Anikeen\Console\AnikeenConsole;
use Anikeen\Console\Exceptions\ApiException;
use Anikeen\Console\Resources\Accepted;
use Anikeen\Console\Resources\Backup;
use Anikeen\Console\Resources\BaseCollection;

/**
 * `v1/containers/{container}/backups`.
 */
class ContainerBackups
{
    public function __construct(
        protected AnikeenConsole $console,
        public readonly string $container,
    ) {
    }

    /**
     * @return BaseCollection<Backup>
     *
     * @throws ApiException
     */
    public function list(): BaseCollection
    {
        return BaseCollection::load(Backup::class, fn () => $this->console->call('GET', $this->path()), $this->console);
    }

    /**
     * @throws ApiException
     */
    public function create(?string $notes = null): Accepted
    {
        return Accepted::fromResult($this->console->call('POST', $this->path(), ['notes' => $notes]), $this->console);
    }

    /**
     * Restore the backup over the container (stopped, restored, started again if it was running).
     *
     * @throws ApiException
     */
    public function restore(string $backup): Accepted
    {
        return Accepted::fromResult($this->console->call('POST', $this->path($backup) . '/restore', []), $this->console);
    }

    /**
     * @throws ApiException
     */
    public function delete(string $backup): Accepted
    {
        return Accepted::fromResult($this->console->call('DELETE', $this->path($backup)), $this->console);
    }

    protected function path(?string $backup = null): string
    {
        $path = 'containers/' . rawurlencode($this->container) . '/backups';

        return $backup === null ? $path : $path . '/' . rawurlencode($backup);
    }
}
