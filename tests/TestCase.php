<?php

namespace Anikeen\Console\Tests;

use Anikeen\Console\AnikeenConsole;
use Anikeen\Console\Providers\AnikeenConsoleServiceProvider;
use Anikeen\Console\Tests\Fixtures\User;
use Anikeen\Id\AnikeenId;
use Anikeen\Id\Providers\AnikeenIdServiceProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends Orchestra
{
    /** Console API responses. */
    protected MockHandler $http;

    /** @var list<array{request: RequestInterface}> */
    protected array $sent = [];

    /** Anikeen ID responses (token refresh). */
    protected MockHandler $idHttp;

    /** @var list<array{request: RequestInterface}> */
    protected array $idSent = [];

    protected function getPackageProviders($app): array
    {
        return [AnikeenIdServiceProvider::class, AnikeenConsoleServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('services.anikeen', ['client_id' => 'gleem-client', 'client_secret' => 'secret']);
        $app['config']->set('services.anikeen_console', ['base_url' => 'https://console.test/v1', 'webhook_secret' => 'whsec_test']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->text('anikeen_id_access_token')->nullable();
            $table->text('anikeen_id_refresh_token')->nullable();
            $table->timestamps();
        });

        $this->http = new MockHandler();
        $stack = HandlerStack::create($this->http);
        $stack->push(Middleware::history($this->sent));
        $this->app->instance(AnikeenConsole::class, new AnikeenConsole(new Client(['handler' => $stack])));

        $this->idHttp = new MockHandler();
        $idStack = HandlerStack::create($this->idHttp);
        $idStack->push(Middleware::history($this->idSent));
        $this->app->instance(AnikeenId::class, (new AnikeenId())->setHttpClient(new Client(['handler' => $idStack, 'base_uri' => 'https://id.test/api/'])));
    }

    protected function console(): AnikeenConsole
    {
        return app(AnikeenConsole::class)->withToken('test-token');
    }

    protected function respond(int $status, array $body = [], array $headers = []): void
    {
        $this->http->append(new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body === [] ? (object) [] : $body)));
    }

    protected function request(int $index): RequestInterface
    {
        return $this->sent[$index]['request'];
    }

    protected function sentJson(int $index): array
    {
        return json_decode((string) $this->request($index)->getBody(), true) ?? [];
    }

    protected function assertRequest(int $index, string $method, string $path, ?array $json = null, array $query = []): void
    {
        $request = $this->request($index);

        $this->assertSame($method, $request->getMethod());
        $this->assertSame('https://console.test/v1/' . $path, (string) $request->getUri()->withQuery(''));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        parse_str($request->getUri()->getQuery(), $sentQuery);
        $this->assertSame($query, $sentQuery);

        if ($json !== null) {
            $this->assertSame($json, $this->sentJson($index));
        }
    }

    /**
     * An unsigned JWT-shaped token with the given claims (the SDK only reads `exp` and `scopes`).
     */
    protected static function jwt(int $expiresIn = 3600, array $scopes = ['console-containers'], string $jti = 'a'): string
    {
        $encode = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $encode(['sub' => '42', 'exp' => time() + $expiresIn, 'scopes' => $scopes, 'jti' => $jti]) . '.sig';
    }

    protected function user(?string $accessToken, ?string $refreshToken = 'refresh-1'): User
    {
        return User::create(['name' => 'Ada', 'anikeen_id_access_token' => $accessToken, 'anikeen_id_refresh_token' => $refreshToken]);
    }
}
