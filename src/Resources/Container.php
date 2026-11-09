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
 * @property stdClass|null $options {features: list<string>, devices: list<string>}, the LXC options of the order
 */
class Container extends Product
{
    /** LXC options of an order (`attributes.options`): FUSE file systems, e.g. gocryptfs */
    public const FEATURE_FUSE = 'fuse';

    /** kernel keyrings, needed by rootless Podman and Docker */
    public const FEATURE_KEYCTL = 'keyctl';

    /** /dev/net/tun: rootless container networking (pasta, slirp4netns), VPNs */
    public const DEVICE_TUN = 'tun';

    /**
     * Whether the container was ordered with the LXC feature (fuse, keyctl).
     */
    public function hasFeature(string $feature): bool
    {
        return in_array($feature, (array) ($this->options->features ?? []), true);
    }

    /**
     * Whether the container has the device of the options (tun).
     */
    public function hasDevice(string $device): bool
    {
        return in_array($device, (array) ($this->options->devices ?? []), true);
    }

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
     * @param  array{features?: list<string>, devices?: list<string>}|null  $options  new LXC options; null keeps them
     *
     * @throws ApiException
     */
    public function reinstall(string $templateId, ?string $sshKey = null, ?array $options = null): Accepted
    {
        return $this->client()->containers()->reinstall($this->id, $templateId, $sshKey, $options);
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
