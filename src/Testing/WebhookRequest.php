<?php

namespace Anikeen\Console\Testing;

use Anikeen\Console\AnikeenConsole;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A webhook as the Console sends it, signed with your secret
 * (AnikeenConsole::webhookRequest($type, $object)). send() posts it through your app's HTTP
 * kernel to the route of Route::anikeenConsoleWebhooks().
 */
class WebhookRequest
{
    /**
     * @param  array<string, mixed>  $event  the envelope {id, type, created, data: {object, …}}
     * @param  string  $body  the raw JSON body that was signed
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly array $event,
        public readonly string $body,
        public readonly array $headers,
    ) {
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $data
     */
    public static function make(string $type, array $object, array $data, string $secret, ?string $id = null, ?int $timestamp = null): self
    {
        $timestamp ??= time();
        $event = [
            'id' => $id ?? 'evt_' . Str::ulid(),
            'type' => $type,
            'created' => $timestamp,
            'data' => ['object' => $object] + $data,
        ];
        $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new self($event, $body, [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Anikeen-Console-Webhooks/1.0',
            'Anikeen-Event-Id' => $event['id'],
            'Anikeen-Event-Type' => $type,
            'Anikeen-Signature' => 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret),
        ]);
    }

    /**
     * The same event (id and body) with other headers, e.g. a broken signature.
     *
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->event, $this->body, array_merge($this->headers, $headers));
    }

    public function toRequest(?string $uri = null): Request
    {
        $server = [];

        foreach ($this->headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_' . $key] = $value;
        }

        return Request::create($uri ?? AnikeenConsole::webhookUri(), 'POST', server: $server, content: $this->body);
    }

    /**
     * Post it through the app's HTTP kernel (in a feature test).
     */
    public function send(?string $uri = null): TestResponse
    {
        $kernel = app(Kernel::class);
        $request = $this->toRequest($uri);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return TestResponse::fromBaseResponse($response, $request);
    }
}
