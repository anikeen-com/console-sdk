<?php

namespace Anikeen\Console\Tests;

use Anikeen\Console\Resources\Team;

class CatalogTest extends TestCase
{
    public function test_the_app_team(): void
    {
        $this->respond(200, ['data' => ['id' => 'team_1', 'name' => 'Gleem']]);

        $team = $this->console()->appTeam();

        $this->assertRequest(0, 'GET', 'app-team');
        $this->assertInstanceOf(Team::class, $team);
        $this->assertSame(['id' => 'team_1', 'name' => 'Gleem'], $team->toArray());
    }

    public function test_the_catalog_of_a_team(): void
    {
        $this->respond(200, [
            'data' => [['family' => 'container', 'name' => 'Container', 'products' => [['id' => '01JPLN', 'name' => 'CT S', 'price' => 0.01]], 'options' => ['locations' => [['id' => 'bon1']]]]],
            'team' => ['reseller' => false, 'coupons_allowed' => true, 'may_order' => true],
            'vat_rate' => 19.0,
        ]);

        $catalog = $this->console()->catalog(Team::make(['id' => 'team_1']));

        $this->assertRequest(0, 'GET', 'catalog', query: ['team' => 'team_1']);
        $this->assertSame(19.0, $catalog->vatRate());
        $this->assertTrue($catalog->team->may_order);
        $this->assertSame('01JPLN', $catalog->products('container')[0]->id);
        $this->assertSame('bon1', $catalog->options('container')->locations[0]->id);
        $this->assertNull($catalog->family('server'));
        $this->assertSame([], $catalog->products('server'));
    }

    public function test_the_catalog_without_team(): void
    {
        $this->respond(200, ['data' => [], 'team' => null, 'vat_rate' => 19.0]);

        $catalog = $this->console()->catalog();

        $this->assertRequest(0, 'GET', 'catalog');
        $this->assertSame([], $catalog->families());
    }
}
