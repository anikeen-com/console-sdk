<?php

namespace Anikeen\Console\Tests;

use Anikeen\Console\AnikeenConsole;

class ConfigurationTest extends TestCase
{
    public function test_production_is_the_default(): void
    {
        config(['services.anikeen_console' => []]);

        $this->assertSame('https://console.anikeen.com/api/v1', (new AnikeenConsole())->getBaseUrl());
    }

    public function test_staging_mode_uses_the_staging_api(): void
    {
        config(['services.anikeen_console' => ['mode' => 'staging']]);

        $this->assertSame('https://staging.console.anikeen.com/api/v1', (new AnikeenConsole())->getBaseUrl());
    }

    public function test_a_base_url_wins(): void
    {
        config(['services.anikeen_console' => ['mode' => 'staging', 'base_url' => 'http://console.localhost/api/v1/']]);

        $this->assertSame('http://console.localhost/api/v1', (new AnikeenConsole())->getBaseUrl());
    }

    public function test_requests_identify_the_sdk(): void
    {
        $this->respond(200, ['data' => ['id' => 'team_1', 'name' => 'Gleem']]);

        $this->console()->appTeam();

        $this->assertRequest(0, 'GET', 'app-team');
        $this->assertSame('anikeen-console-php/' . AnikeenConsole::VERSION, $this->request(0)->getHeaderLine('User-Agent'));
    }
}
