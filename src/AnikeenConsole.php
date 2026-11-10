<?php

namespace Anikeen\Console;

use Anikeen\Console\Endpoints\Containers;
use Anikeen\Console\Endpoints\Orders;
use Anikeen\Console\Exceptions\ApiException;
use Anikeen\Console\Exceptions\AuthenticationException;
use Anikeen\Console\Exceptions\ValidationFailedException;
use Anikeen\Console\Resources\Catalog;
use Anikeen\Console\Resources\Team;
use Anikeen\Console\Testing\WebhookRequest;
use Anikeen\Id\AnikeenId;
use Anikeen\Id\Exceptions\RequestFreshAccessTokenException;
use Anikeen\Id\Exceptions\RequestRequiresAuthenticationException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Client for the Anikeen Console API (facade `AnikeenConsole`). The shared instance holds no token:
 * forUser() and withToken() return a copy bound to one token, so tokens never leak between users.
 */
class AnikeenConsole
{
    public const VERSION = '1.0.0';

    /**
     * Seconds before `exp` at which a stored token counts as expired and is refreshed first.
     */
    public const EXPIRY_LEEWAY = 60;

    protected string $baseUrl = 'https://console.anikeen.com/api/v1';

    protected string $stagingBaseUrl = 'https://staging.console.anikeen.com/api/v1';

    protected Client $client;

    /**
     * A fixed token (withToken()).
     */
    protected ?string $token = null;

    /**
     * The user whose stored Anikeen ID tokens are used and refreshed (forUser()).
     */
    protected ?object $user = null;

    /**
     * The URI the webhook route was registered at (Route::anikeenConsoleWebhooks()).
     */
    protected static ?string $webhookUri = null;

    public function __construct(?Client $client = null)
    {
        if (static::getMode() === 'staging') {
            $this->baseUrl = $this->stagingBaseUrl;
        }
        if ($baseUrl = config('services.anikeen_console.base_url')) {
            $this->baseUrl = $baseUrl;
        }

        $this->client = $client ?? new Client(['timeout' => 30, 'connect_timeout' => 10]);
    }

    public static function getMode(): string
    {
        return config('services.anikeen_console.mode') ?: 'production';
    }

    public function getBaseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    /**
     * Replace the HTTP client, e.g. with one using a Guzzle MockHandler in tests.
     */
    public function setHttpClient(Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    /**
     * A client acting for $user with their stored Anikeen ID tokens (the fields of
     * AnikeenId::getAccessTokenField() / getRefreshTokenField()). An expired token is refreshed
     * first; a 401 refreshes it and retries once. The new pair is saved on the user.
     */
    public function forUser(object $user): static
    {
        $console = clone $this;
        $console->user = $user;
        $console->token = null;

        return $console;
    }

    /**
     * A client with a fixed token (e.g. a personal access token in a script). Never refreshed.
     */
    public function withToken(string $token): static
    {
        $console = clone $this;
        $console->token = $token;
        $console->user = null;

        return $console;
    }

    /**
     * The team the app works in for this user (`GET v1/app-team`, created on the first call).
     * Order into it; other teams of the user answer 404 for app tokens.
     *
     * @throws ApiException
     */
    public function appTeam(): Team
    {
        return Team::fromResult($this->call('GET', 'app-team'), $this);
    }

    /**
     * The orderable products per family (`GET v1/catalog`), filtered to the token's scopes. With a
     * team: its prices, VAT rate and the `options` for the order attributes (locations, templates, …).
     *
     * $filters narrow the products server-side (families left without products are left out):
     * `['gpu' => true, 'gpu_vendor' => 'nvidia', 'max_price' => 150, 'sort' => 'price']`.
     * Booleans are sent as `true`/`false`, a list (`'family' => ['container', 'server']`) comma
     * separated, null values are left out. Prices compare `monthly_price` (net, the team's price).
     *
     * @param  array{family?: string|list<string>, gpu?: bool, gpu_vendor?: 'nvidia'|'amd'|'intel', gpu_model?: string, gpu_mode?: 'shared'|'exclusive', gpu_min_vram_gb?: int, gpu_min_count?: int, min_vcores?: int, min_memory_mb?: int, min_disk_gb?: int, min_price?: float|int, max_price?: float|int, sort?: 'price'|'-price'}  $filters
     *
     * @throws ApiException
     * @throws ValidationFailedException a filter with a bad value (422)
     */
    public function catalog(Team|string|null $team = null, array $filters = []): Catalog
    {
        $team = $team instanceof Team ? $team->id : $team;
        $query = array_filter(['team' => $team]);

        foreach ($filters as $key => $value) {
            if ($value === null || $key === 'team') {
                continue;
            }

            $query[$key] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_array($value) => implode(',', $value),
                default => (string) $value,
            };
        }

        return Catalog::fromResult($this->call('GET', 'catalog', query: $query), $this);
    }

    /**
     * The orders of $team: create (with an Idempotency-Key), list and find.
     */
    public function orders(Team|string $team): Orders
    {
        return new Orders($this, $team instanceof Team ? $team->id : $team);
    }

    /**
     * Containers (needs `console-containers`, or `:read` for reading).
     */
    public function containers(): Containers
    {
        return new Containers($this);
    }

    /**
     * A signed webhook request as the Console would send it, for your tests:
     * `AnikeenConsole::webhookRequest(EventType::PRODUCT_READY, [...])->send()`.
     *
     * @param  array<string, mixed>  $object  `data.object` of the event
     * @param  array<string, mixed>  $data  further keys of `data`
     */
    public function webhookRequest(string $type, array $object = [], array $data = [], ?string $secret = null): WebhookRequest
    {
        return WebhookRequest::make($type, $object, $data, $secret ?? (string) config('services.anikeen_console.webhook_secret'));
    }

    /**
     * @internal set by Route::anikeenConsoleWebhooks()
     */
    public static function useWebhookUri(string $uri): void
    {
        static::$webhookUri = '/' . ltrim($uri, '/');
    }

    public static function webhookUri(): string
    {
        return static::$webhookUri ?? '/webhooks/anikeen-console';
    }

    /**
     * Send a request and throw the matching ApiException when it fails.
     *
     * @param  array<string, mixed>|null  $payload  JSON body
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     *
     * @throws ApiException
     */
    public function call(string $method, string $path, ?array $payload = null, array $query = [], array $headers = []): Result
    {
        $result = $this->send($method, $path, $payload, $query, $headers);

        if (!$result->success()) {
            throw ApiException::from($result);
        }

        return $result;
    }

    /**
     * Send a request (relative to the base URL) and return the result, also for 4xx/5xx. Bound to
     * a user, a 401 refreshes the token and sends the same request (same headers, so the same
     * Idempotency-Key) once more.
     *
     * @param  array<string, mixed>|null  $payload  JSON body
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     *
     * @throws ApiException no response at all, or the token could not be refreshed
     */
    public function send(string $method, string $path, ?array $payload = null, array $query = [], array $headers = []): Result
    {
        $token = $this->currentToken();
        $result = $this->dispatch($method, $path, $payload, $query, $headers, $token);

        if ($result->status === 401 && $result->errorCode() !== 'client_not_allowed' && $this->canRefresh()) {
            $result = $this->dispatch($method, $path, $payload, $query, $headers, $this->refreshToken($token));
        }

        return $result;
    }

    /**
     * @throws ApiException
     */
    protected function dispatch(string $method, string $path, ?array $payload, array $query, array $headers, string $token): Result
    {
        $options = [
            'headers' => array_merge($headers, [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'User-Agent' => 'anikeen-console-php/' . static::VERSION,
            ]),
            'query' => $query,
        ];

        if ($payload !== null) {
            $options['json'] = $payload === [] ? (object) [] : $payload;
        }

        try {
            return new Result($this->client->request($method, $this->getBaseUrl() . '/' . ltrim($path, '/'), $options));
        } catch (RequestException $exception) {
            if ($exception->getResponse() === null) {
                throw ApiException::unreachable($exception);
            }

            return new Result($exception->getResponse(), $exception);
        } catch (GuzzleException $exception) {
            throw ApiException::unreachable($exception);
        }
    }

    /**
     * The token for the next request; a stored token about to expire is refreshed first.
     *
     * @throws RequestRequiresAuthenticationException no token at all
     * @throws ApiException
     */
    protected function currentToken(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        if ($this->user === null) {
            throw new RequestRequiresAuthenticationException('Use AnikeenConsole::forUser($user) or withToken($token) first.');
        }

        $token = $this->storedAccessToken();

        if ($token === null) {
            if (!$this->canRefresh()) {
                throw new RequestRequiresAuthenticationException('The user has no Anikeen ID access token.');
            }

            return $this->refreshToken(null);
        }

        if ($this->canRefresh() && static::tokenExpiresWithin($token, static::EXPIRY_LEEWAY)) {
            return $this->refreshToken($token);
        }

        return $token;
    }

    protected function canRefresh(): bool
    {
        return $this->user !== null && $this->token === null && $this->storedRefreshToken() !== null;
    }

    /**
     * Refresh the user's token pair with Anikeen ID and store it. Locked per user, and a pair
     * another process stored meanwhile is taken instead of refreshing again (refresh tokens are
     * single-use).
     *
     * @param  string|null  $staleToken  the access token that expired or got a 401
     *
     * @throws AuthenticationException Anikeen ID refused the refresh token
     * @throws ApiException Anikeen ID not reachable
     */
    protected function refreshToken(?string $staleToken): string
    {
        $store = Cache::getStore();

        if (!$store instanceof LockProvider) {
            return $this->performRefresh($staleToken);
        }

        $key = 'anikeen-console:token-refresh:' . sha1(get_class($this->user) . '|' . $this->userKey());

        return Cache::lock($key, 30)->block(15, fn () => $this->performRefresh($staleToken));
    }

    protected function performRefresh(?string $staleToken): string
    {
        $stored = $this->reloadStoredTokens();

        if ($stored !== null && $stored[0] !== null && $stored[0] !== $staleToken && !static::tokenExpiresWithin($stored[0], static::EXPIRY_LEEWAY)) {
            // refreshed by another request meanwhile
            $this->fillTokens($stored[0], $stored[1]);

            return $stored[0];
        }

        $refreshToken = $stored[1] ?? $this->storedRefreshToken();

        if ($refreshToken === null) {
            throw new AuthenticationException('The user has no Anikeen ID refresh token.', 401);
        }

        try {
            $result = app(AnikeenId::class)->refreshToken($refreshToken, implode(' ', static::tokenScopes($staleToken)));
        } catch (GuzzleException $exception) {
            throw new ApiException(sprintf('Anikeen ID is not reachable: %s', $exception->getMessage()), 0, null, $exception);
        }

        $accessToken = $result->data->access_token ?? null;

        if (!$result->success() || !is_string($accessToken) || $accessToken === '') {
            $previous = $result->response() ? RequestFreshAccessTokenException::fromResponse($result->response()) : $result->exception;

            throw new AuthenticationException('The Anikeen ID token could not be refreshed; the user has to sign in again.', 401, null, $previous);
        }

        $newRefreshToken = $result->data->refresh_token ?? $refreshToken;

        $this->saveTokens($accessToken, $newRefreshToken);

        return $accessToken;
    }

    protected function storedAccessToken(): ?string
    {
        $value = $this->user->{AnikeenId::getAccessTokenField()} ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function storedRefreshToken(): ?string
    {
        $value = $this->user->{AnikeenId::getRefreshTokenField()} ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The pair as stored now (another process may have refreshed it), null when it can't be read.
     *
     * @return array{0: ?string, 1: ?string}|null
     */
    protected function reloadStoredTokens(): ?array
    {
        if (!$this->user instanceof Model || !$this->user->exists) {
            return null;
        }

        $fresh = $this->user->newQueryWithoutScopes()->whereKey($this->user->getKey())->first();

        if ($fresh === null) {
            return null;
        }

        $access = $fresh->getAttribute(AnikeenId::getAccessTokenField());
        $refresh = $fresh->getAttribute(AnikeenId::getRefreshTokenField());

        return [is_string($access) && $access !== '' ? $access : null, is_string($refresh) && $refresh !== '' ? $refresh : null];
    }

    /**
     * Store the new pair: on an Eloquent user only these two columns are saved (through a fresh
     * instance, so other unsaved changes of $user stay unsaved; casts like `encrypted` apply).
     */
    protected function saveTokens(string $accessToken, ?string $refreshToken): void
    {
        $values = [AnikeenId::getAccessTokenField() => $accessToken, AnikeenId::getRefreshTokenField() => $refreshToken];

        if ($this->user instanceof Model) {
            if ($this->user->exists && $fresh = $this->user->newQueryWithoutScopes()->whereKey($this->user->getKey())->first()) {
                $fresh->forceFill($values)->save();
            } else {
                $this->user->forceFill($values)->save();
            }
        } elseif (method_exists($this->user, 'save')) {
            foreach ($values as $field => $value) {
                $this->user->{$field} = $value;
            }
            $this->user->save();
        }

        $this->fillTokens($accessToken, $refreshToken);
    }

    protected function fillTokens(string $accessToken, ?string $refreshToken): void
    {
        $values = [AnikeenId::getAccessTokenField() => $accessToken, AnikeenId::getRefreshTokenField() => $refreshToken];

        if ($this->user instanceof Model) {
            $this->user->forceFill($values)->syncOriginalAttributes(array_keys($values));

            return;
        }

        foreach ($values as $field => $value) {
            $this->user->{$field} = $value;
        }
    }

    protected function userKey(): string
    {
        if ($this->user instanceof Model) {
            return (string) $this->user->getKey();
        }

        return (string) ($this->user->{AnikeenId::getUserIdField()} ?? spl_object_id($this->user));
    }

    /**
     * Whether the JWT's `exp` is within $seconds from now. Not a JWT, or no `exp`: false.
     * The signature is not checked; the Console does that.
     */
    public static function tokenExpiresWithin(string $token, int $seconds = 0): bool
    {
        $exp = static::tokenClaims($token)['exp'] ?? null;

        return is_numeric($exp) && (int) $exp <= time() + $seconds;
    }

    /**
     * @return list<string>
     */
    protected static function tokenScopes(?string $token): array
    {
        $scopes = $token ? (static::tokenClaims($token)['scopes'] ?? []) : [];

        return is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function tokenClaims(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return [];
        }

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = $json !== false ? json_decode($json, true) : null;

        return is_array($claims) ? $claims : [];
    }

    /**
     * @internal the user this client acts for
     */
    public function user(): ?object
    {
        return $this->user;
    }
}
