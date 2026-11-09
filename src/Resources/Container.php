<?php

namespace Anikeen\Console\Resources;

use Anikeen\Console\Endpoints\ContainerBackups;
use Anikeen\Console\Endpoints\ContainerSnapshots;
use Anikeen\Console\Exceptions\ApiException;
use stdClass;

/**
 * A container (family `container`). `status` (running, stopped, null when unknown) is only set
 * when read with find(), not in lists.
 *
 * @property string $id
 * @property string $name
 * @property string|null $status
 * @property stdClass|null $location {id, name}
 * @property stdClass|null $template {id, name}
 * @property stdClass|null $gpu {vendor, model, vram_gb, mode, count}
 */
class Container extends Product
{
    /**
     * Rename the container (the hostname inside changes with the next restart).
     *
     * @param  array{name?: string}  $attributes
     *
     * @throws ApiException
     */
    public function update(array $attributes): static
    {
        return $this->client()->containers()->update($this->id, $attributes);
    }

    /**
     * Delete the container (confirmed with its own name).
     *
     * @throws ApiException
     */
    public function delete(): void
    {
        $this->client()->containers()->delete($this->id, (string) $this->name);
    }

    /**
     * @param  string  $action  start, stop, shutdown or reboot
     *
     * @throws ApiException
     */
    public function power(string $action): Accepted
    {
        return $this->client()->containers()->power($this->id, $action);
    }

    /**
     * @throws ApiException
     */
    public function reinstall(string $templateId, ?string $sshKey = null): Accepted
    {
        return $this->client()->containers()->reinstall($this->id, $templateId, $sshKey);
    }

    public function backups(): ContainerBackups
    {
        return $this->client()->containers()->backups($this->id);
    }

    public function snapshots(): ContainerSnapshots
    {
        return $this->client()->containers()->snapshots($this->id);
    }

    /**
     * Read the container again (with `status`).
     *
     * @throws ApiException
     */
    public function refresh(): static
    {
        return $this->client()->containers()->find($this->id);
    }
}
