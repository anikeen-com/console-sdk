<?php

namespace Anikeen\Console\Facades;

use Anikeen\Console\AnikeenConsole as AnikeenConsoleClient;
use Anikeen\Console\Testing\AnikeenConsoleFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static AnikeenConsoleClient forUser(object $user)
 * @method static AnikeenConsoleClient withToken(string $token)
 * @method static \Anikeen\Console\Testing\WebhookRequest webhookRequest(string $type, array $object = [], array $data = [], ?string $secret = null)
 * @method static string getBaseUrl()
 *
 * @see AnikeenConsoleClient
 * @see AnikeenConsoleFake
 */
class AnikeenConsole extends Facade
{
    /**
     * Answer the Console API with fakes instead of sending requests, and record the requests.
     * Keys are `"METHOD path"` or `"path"` patterns relative to the base URL (`*` matches
     * anything), values the response: an array (JSON, 200 / 201 for POST), a
     * GuzzleHttp\Psr7\Response, or a callable getting the RecordedRequest.
     * `AnikeenConsole::fake(['containers/*' => ['id' => '01J…', 'name' => 'ct-1']])`.
     *
     * @param  array<string, mixed>  $responses
     */
    public static function fake(array $responses = []): AnikeenConsoleFake
    {
        $fake = new AnikeenConsoleFake($responses);

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return AnikeenConsoleClient::class;
    }
}
