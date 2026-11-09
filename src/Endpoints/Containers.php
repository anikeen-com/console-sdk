<?php

namespace Anikeen\Console\Endpoints;

use Anikeen\Console\AnikeenConsole;
use Anikeen\Console\Exceptions\ApiException;
use Anikeen\Console\Resources\Accepted;
use Anikeen\Console\Resources\BaseCollection;
use Anikeen\Console\Resources\Container;
use Anikeen\Id\Helpers\Paginator;

/**
 * `v1/containers` (scope `console-containers`, `:read` for GET). Containers are created through
 * orders (family `container`). Operations answering `202` run in the background.
 */
class Containers
{
    public const POWER_ACTIONS = ['start', 'stop', 'shutdown', 'reboot'];

    public function __construct(protected AnikeenConsole $console)
    {
    }

    /**
     * The containers the token may see (for an app: those of its team), without `status`.
     *
     * @param  array{page?: int}  $query
     *
     * @return BaseCollection<Container>
     *
     * @throws ApiException
     */
    public function list(array $query = []): BaseCollection
    {
        return BaseCollection::load(
            Container::class,
            fn (?Paginator $paginator) => $this->console->call('GET', 'containers', query: $paginator ? ['page' => $paginator->cursor()] + $query : $query),
            $this->console,
        );
    }

    /**
     * @throws ApiException 404 NotFoundException
     */
    public function find(string $id): Container
    {
        return Container::fromResult($this->console->call('GET', $this->path($id)), $this->console);
    }

    /**
     * @param  array{name?: string}  $attributes  `name`: the hostname, changes inside with the next restart
     *
     * @throws ApiException
     */
    public function update(string $id, array $attributes): Container
    {
        return Container::fromResult($this->console->call('PATCH', $this->path($id), $attributes), $this->console);
    }

    /**
     * Delete a container; `$name` (its current name) confirms it. 409 while the deletion protection
     * is on. Kept 14 days, then purged.
     *
     * @throws ApiException
     */
    public function delete(string $id, string $name): void
    {
        $this->console->call('DELETE', $this->path($id), ['name' => $name]);
    }

    /**
     * @param  string  $action  start, stop, shutdown or reboot (409 for start/reboot while suspended)
     *
     * @throws ApiException
     */
    public function power(string $id, string $action): Accepted
    {
        return Accepted::fromResult($this->console->call('POST', $this->path($id) . '/power', ['action' => $action]), $this->console);
    }

    /**
     * Reinstall with a template of the container's node. All data and snapshots are lost.
     *
     * @param  string|null  $sshKey  an SSH key of the team
     *
     * @throws ApiException
     */
    public function reinstall(string $id, string $templateId, ?string $sshKey = null): Accepted
    {
        $payload = ['template_id' => $templateId];

        if ($sshKey !== null) {
            $payload['ssh_key'] = $sshKey;
        }

        return Accepted::fromResult($this->console->call('POST', $this->path($id) . '/reinstall', $payload), $this->console);
    }

    public function backups(string $id): ContainerBackups
    {
        return new ContainerBackups($this->console, $id);
    }

    public function snapshots(string $id): ContainerSnapshots
    {
        return new ContainerSnapshots($this->console, $id);
    }

    protected function path(string $id): string
    {
        return 'containers/' . rawurlencode($id);
    }
}
