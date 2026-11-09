<?php

namespace Anikeen\Console\Tests;

use Anikeen\Console\AnikeenConsole as AnikeenConsoleClient;
use Anikeen\Console\Exceptions\MissingScopeException;
use Anikeen\Console\Facades\AnikeenConsole;
use Anikeen\Console\Testing\AnikeenConsoleFake;
use Anikeen\Console\Testing\RecordedRequest;
use PHPUnit\Framework\AssertionFailedError;

class FakeTest extends TestCase
{
    public function test_orders_are_faked_and_asserted(): void
    {
        $fake = AnikeenConsole::fake();
        $user = $this->user(null, null);

        $console = AnikeenConsole::forUser($user);
        $team = $console->appTeam();
        $order = $console->orders($team)->create([['family' => 'container', 'product_id' => '01JPLN']], ['tos_accepted' => true]);

        $this->assertSame('team_fake', $team->id);
        $this->assertTrue($order->isOpen());
        $this->assertStringStartsWith('https://id.anikeen.com/checkout/sessions/', $order->checkout_url);
        $this->assertSame('container', $order->items[0]->family);

        $fake->assertOrdered();
        $fake->assertOrdered(fn (array $items, array $body, string $teamId) => $items[0]['product_id'] === '01JPLN' && $teamId === 'team_fake' && $body['tos_accepted'] === true);
        $fake->assertSent('GET app-team');
        $fake->assertNotSent('GET containers');
        $this->assertSame('fake-token', substr($fake->recorded()[0]->header('Authorization'), 7));
        $this->assertCount(0, $this->sent);
        $this->assertTrue($fake === app(AnikeenConsoleClient::class));
    }

    public function test_assert_ordered_fails_without_order(): void
    {
        $fake = AnikeenConsole::fake();

        $fake->assertNothingOrdered();
        $fake->assertNothingSent();

        $this->expectException(AssertionFailedError::class);
        $fake->assertOrdered();
    }

    public function test_assert_nothing_ordered_fails_after_an_order(): void
    {
        $fake = AnikeenConsole::fake();
        AnikeenConsole::withToken('t')->orders('team_1')->create([], ['tos_accepted' => true]);

        $this->expectException(AssertionFailedError::class);
        $fake->assertNothingOrdered();
    }

    public function test_responses_by_pattern(): void
    {
        AnikeenConsole::fake([
            'GET containers/01JCT' => ['id' => '01JCT', 'name' => 'ct-1', 'status' => 'running'],
            'containers/*/power' => fn (RecordedRequest $request) => ['message' => 'Doing ' . $request->json['action']],
            'GET containers' => AnikeenConsoleFake::response(['message' => 'The access token needs the scope "console-containers".', 'error' => 'missing_scope'], 403),
        ]);
        $console = AnikeenConsole::withToken('t');

        $container = $console->containers()->find('01JCT');
        $accepted = $container->power('stop');

        $this->assertSame('running', $container->status);
        $this->assertSame('Doing stop', $accepted->message);
        $this->expectException(MissingScopeException::class);
        $console->containers()->list();
    }

    public function test_defaults_for_lists_and_actions(): void
    {
        $fake = AnikeenConsole::fake();
        $console = AnikeenConsole::withToken('t');

        $this->assertCount(0, $console->containers()->list());
        $this->assertCount(0, $console->containers()->backups('01JCT')->list());
        $this->assertSame('Accepted.', $console->containers()->snapshots('01JCT')->create('s1')->message);
        $console->containers()->delete('01JCT', 'ct-1');
        $this->assertSame([], $console->catalog()->families());

        $fake->assertSent(fn (RecordedRequest $request) => $request->method === 'DELETE' && $request->json === ['name' => 'ct-1']);
        $this->assertCount(5, $fake->recorded());
    }
}
