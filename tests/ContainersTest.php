<?php

namespace Anikeen\Console\Tests;

use Anikeen\Console\Resources\Accepted;
use Anikeen\Console\Resources\Backup;
use Anikeen\Console\Resources\Container;
use Anikeen\Console\Resources\Snapshot;
use GuzzleHttp\Psr7\Response;

class ContainersTest extends TestCase
{
    private function container(array $overrides = []): array
    {
        return $overrides + ['id' => '01JCT', 'name' => 'ct-1.example', 'status' => 'running', 'location' => ['id' => 'bon1', 'name' => 'Bonn'], 'template' => ['id' => '01JTPL', 'name' => 'Debian 13'], 'gpu' => null];
    }

    public function test_containers_are_listed(): void
    {
        $this->respond(200, ['current_page' => 1, 'data' => [$this->container(['status' => null])], 'first_page_url' => 'https://console.test/v1/containers?page=1', 'last_page' => 1, 'next_page_url' => null, 'prev_page_url' => null, 'per_page' => 15, 'total' => 1]);

        $containers = $this->console()->containers()->list();

        $this->assertRequest(0, 'GET', 'containers');
        $this->assertCount(1, $containers);
        $this->assertSame(1, $containers->total());
        $this->assertFalse($containers->hasMorePages());
        $this->assertInstanceOf(Container::class, $containers->first());
        $this->assertSame('Bonn', $containers->first()->location->name);
    }

    public function test_the_next_page_of_a_plain_paginator(): void
    {
        $this->respond(200, ['current_page' => 1, 'data' => [$this->container()], 'last_page' => 2, 'next_page_url' => 'https://console.test/v1/containers?page=2', 'prev_page_url' => null, 'per_page' => 1, 'total' => 2]);
        $this->respond(200, ['current_page' => 2, 'data' => [$this->container(['id' => '01JCT2'])], 'last_page' => 2, 'next_page_url' => null, 'prev_page_url' => 'https://console.test/v1/containers?page=1', 'per_page' => 1, 'total' => 2]);

        $page = $this->console()->containers()->list()->nextPage();

        $this->assertRequest(1, 'GET', 'containers', query: ['page' => '2']);
        $this->assertSame('01JCT2', $page->first()->id);
    }

    public function test_a_container_is_found_and_acted_on(): void
    {
        $this->respond(200, $this->container());
        $this->respond(202, ['message' => 'The container is starting.']);
        $this->respond(202, ['message' => 'Reinstalling.']);

        $container = $this->console()->containers()->find('01JCT');
        $accepted = $container->power('start');
        $container->reinstall('01JTPL2', 'key-1');

        $this->assertRequest(0, 'GET', 'containers/01JCT');
        $this->assertSame('running', $container->status);
        $this->assertRequest(1, 'POST', 'containers/01JCT/power', ['action' => 'start']);
        $this->assertInstanceOf(Accepted::class, $accepted);
        $this->assertSame('The container is starting.', $accepted->message);
        $this->assertRequest(2, 'POST', 'containers/01JCT/reinstall', ['template_id' => '01JTPL2', 'ssh_key' => 'key-1']);
    }

    public function test_a_reinstall_without_ssh_key(): void
    {
        $this->respond(202, ['message' => 'Reinstalling.']);

        $this->console()->containers()->reinstall('01JCT', '01JTPL');

        $this->assertRequest(0, 'POST', 'containers/01JCT/reinstall', ['template_id' => '01JTPL']);
    }

    public function test_a_container_is_renamed(): void
    {
        $this->respond(200, $this->container(['name' => 'web']));

        $container = $this->console()->containers()->update('01JCT', ['name' => 'web']);

        $this->assertRequest(0, 'PATCH', 'containers/01JCT', ['name' => 'web']);
        $this->assertSame('web', $container->name);
    }

    public function test_a_container_is_deleted_with_its_name(): void
    {
        $this->respond(200, $this->container());
        $this->http->append(new Response(204));

        $this->console()->containers()->find('01JCT')->delete();

        $this->assertRequest(1, 'DELETE', 'containers/01JCT', ['name' => 'ct-1.example']);
    }

    public function test_backups(): void
    {
        $this->respond(200, ['data' => [['id' => 'local:backup/vzdump-lxc-101.tar.zst', 'notes' => 'before update', 'size' => 1024, 'created_at' => '2026-11-09T12:00:00+00:00']]]);
        $this->respond(202, ['message' => 'Backup started.']);
        $this->respond(202, ['message' => 'Restoring.']);
        $this->respond(202, ['message' => 'Deleting.']);
        $backups = $this->console()->containers()->backups('01JCT');

        $list = $backups->list();
        $backups->create('before update');
        $backups->restore($list->first()->id);
        $backups->delete($list->first()->id);

        $this->assertRequest(0, 'GET', 'containers/01JCT/backups');
        $this->assertInstanceOf(Backup::class, $list->first());
        $this->assertSame(1024, $list->first()->size);
        $this->assertRequest(1, 'POST', 'containers/01JCT/backups', ['notes' => 'before update']);
        $this->assertRequest(2, 'POST', 'containers/01JCT/backups/local%3Abackup%2Fvzdump-lxc-101.tar.zst/restore');
        $this->assertRequest(3, 'DELETE', 'containers/01JCT/backups/local%3Abackup%2Fvzdump-lxc-101.tar.zst');
    }

    public function test_snapshots(): void
    {
        $this->respond(200, ['data' => [['name' => 'before_update', 'description' => null, 'parent' => null, 'created_at' => '2026-11-09T12:00:00+00:00']]]);
        $this->respond(202, ['message' => 'Snapshot started.']);
        $this->respond(202, ['message' => 'Rolling back.']);
        $this->respond(202, ['message' => 'Deleting.']);
        $snapshots = $this->console()->containers()->snapshots('01JCT');

        $list = $snapshots->list();
        $snapshots->create('before_update', 'Before the update');
        $snapshots->rollback('before_update');
        $snapshots->delete('before_update');

        $this->assertInstanceOf(Snapshot::class, $list->first());
        $this->assertRequest(0, 'GET', 'containers/01JCT/snapshots');
        $this->assertRequest(1, 'POST', 'containers/01JCT/snapshots', ['name' => 'before_update', 'description' => 'Before the update']);
        $this->assertRequest(2, 'POST', 'containers/01JCT/snapshots/before_update/rollback');
        $this->assertRequest(3, 'DELETE', 'containers/01JCT/snapshots/before_update');
    }

    public function test_actions_through_the_container_resource(): void
    {
        $this->respond(200, $this->container());
        $this->respond(200, ['data' => []]);
        $this->respond(200, ['data' => []]);

        $container = $this->console()->containers()->find('01JCT');
        $container->backups()->list();
        $container->snapshots()->list();

        $this->assertRequest(1, 'GET', 'containers/01JCT/backups');
        $this->assertRequest(2, 'GET', 'containers/01JCT/snapshots');
    }

    public function test_a_container_without_client_says_how_to_bind_one(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('withClient');

        Container::make(['id' => '01JCT'])->power('start');
    }

    public function test_a_container_from_a_webhook_gets_a_client(): void
    {
        $this->respond(202, ['message' => 'Stopping.']);

        Container::make(['id' => '01JCT'])->withClient($this->console())->power('stop');

        $this->assertRequest(0, 'POST', 'containers/01JCT/power', ['action' => 'stop']);
    }
}
