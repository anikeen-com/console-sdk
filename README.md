# Anikeen Console

PHP client for the Anikeen Console API, for Laravel 12+ (PHP 8.2+).

Lets a trusted app act in the Anikeen Console **for its users, with the user's own Anikeen ID
token**: order products (the user confirms and pays on Anikeen ID), manage them, and learn about
changes through webhooks. Built on [`anikeen/id`](https://github.com/anikeen-com/id), which handles
the login, the tokens and the webhook signatures.

## Table of contents

1. [How it works](#how-it-works)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Scopes](#scopes)
5. [Usage](#usage)
6. [Errors](#errors)
7. [Webhooks](#webhooks)
8. [Testing your app](#testing-your-app)
9. [Development](#development)

## How it works

```
your user ── login (Anikeen ID, scope console-containers) ──▶ your app stores the token pair
your app  ── AnikeenConsole::forUser($user)->appTeam() ──▶ the Console creates the user's team for your app
your app  ── ->orders($team)->create([...]) ──▶ open order with checkout_url
your user ── confirms on Anikeen ID ──▶ webhook order.completed, later product.ready
```

- The Console accepts tokens of **trusted** apps only (otherwise `401 client_not_allowed`).
- Every app works in **its own team** of the user ("app team"), created on the first call. Other
  teams and products of the user are invisible to your app (`404`). The team and its products are
  read-only for the user in the Console UI; your app changes them.
- The user does not need a Console account: it is created on the first call.
- Orders are never charged directly: the order is `open` with a `checkout_url`, the user confirms
  it on Anikeen ID.

## Installation

The repository is private for now; add it as a Composer VCS repository (with a GitHub token or
deploy key in `auth.json`):

```json
"repositories": [
    {"type": "vcs", "url": "https://github.com/anikeen-com/console-sdk"}
]
```

```
composer require anikeen/console
```

The service provider and the `AnikeenConsole` facade are registered through package discovery.
Set up `anikeen/id` (login with Anikeen ID, storing the tokens on the user) first; see its README.

## Configuration

```
ANIKEEN_CONSOLE_MODE=staging            # optional; production by default
ANIKEEN_CONSOLE_BASE_URL=               # optional, overrides the mode (e.g. a local Console)
ANIKEEN_CONSOLE_WEBHOOK_SECRET=whsec_…  # the signing secret of your webhook endpoint
```

Add to `config/services.php` (so it still works with `php artisan config:cache`):

```php
'anikeen_console' => [
    'mode' => env('ANIKEEN_CONSOLE_MODE'),
    'base_url' => env('ANIKEEN_CONSOLE_BASE_URL'),
    'webhook_secret' => env('ANIKEEN_CONSOLE_WEBHOOK_SECRET'),
],
```

| Mode | Base URL |
|------|----------|
| `production` (default) | `https://api.console.anikeen.com/v1` |
| `staging` | `https://staging.console.anikeen.com/api/v1` |

Use `ANIKEEN_ID_MODE=staging` together with `ANIKEEN_CONSOLE_MODE=staging`: the staging Console
only accepts tokens of staging Anikeen ID.

The token refresh uses `anikeen/id` (`services.anikeen.client_id` / `client_secret`) and the token
fields it uses on your user model (`anikeen_id_access_token`, `anikeen_id_refresh_token`, or what
you set with `AnikeenId::useAccessTokenField()` / `useRefreshTokenField()`).

## Scopes

Request the Console scopes in your Anikeen ID login (`Anikeen\Id\Enums\Scope::CONSOLE_*`). One
pair per product family: the full scope reads, changes and orders, `:read` only reads. The catalog
lists the families your token covers; an order needs the scope of every item's family.

| Area | Scope | Read only |
|------|-------|-----------|
| Containers (`containers()`, orders of family `container`) | `console-containers` | `console-containers:read` |
| Servers and floating IPs (not in the SDK yet) | `console-servers` | `console-servers:read` |
| Domains (not in the SDK yet) | `console-domains` | `console-domains:read` |
| DNS zones (not in the SDK yet) | `console-zones` | `console-zones:read` |
| Web hosting (not in the SDK yet) | `console-hosting` | `console-hosting:read` |
| Load balancers (not in the SDK yet) | `console-load-balancers` | `console-load-balancers:read` |
| Object storage (ordering only) | `console-object-storage` | `console-object-storage:read` |
| `appTeam()`, `catalog()`, `orders()->list/find` | any `console-*` scope | |

A missing scope answers `403 missing_scope` (`MissingScopeException::scope()` names it). The
scope `user` does not open the Console API for apps.

```php
return Socialite::driver('anikeen')
    ->scopes([Scope::CONSOLE_CONTAINERS])
    ->redirect();
```

## Usage

```php
use Anikeen\Console\Facades\AnikeenConsole;

$console = AnikeenConsole::forUser($user);  // the user's stored token, refreshed when needed

$team = $console->appTeam();                // GET v1/app-team: {id, name}, created on the first call

// what can be ordered, with the options for the order attributes
$catalog = $console->catalog($team);
$plans = $catalog->products('container');
$locations = $catalog->options('container')->locations;

// only GPU plans, NVIDIA, at most 150 € net per month, cheapest first
$gpuPlans = $console->catalog($team, [
    'gpu' => true,
    'gpu_vendor' => 'nvidia',     // nvidia | amd | intel
    'max_price' => 150,           // on monthly_price: net, the team's price, hourly plans at their monthly cap
    'sort' => 'price',            // price | -price
])->products('container');

$order = $console->orders($team)->create([
    ['family' => 'container', 'product_id' => $planId, 'attributes' => [
        'location_id' => 'bon1',
        'container_template_id' => $templateId,
        'vlan_id' => $vlanId,
        'ssh_key' => $sshKeyId,             // optional
        'name' => 'game-1',                 // optional hostname
    ]],
], ['tos_accepted' => true]);               // required: only once your user accepted the terms

return redirect($order->checkout_url);      // the user confirms (and pays) on Anikeen ID
```

Send the user back to your app after the checkout with `return_url` (app tokens only; https, plain
http only for localhost). The Console redirects there with the order id and its status appended:

```php
$order = $console->orders($team)->create($items, [
    'tos_accepted' => true,
    'return_url' => route('ordered'),       // → https://your.app/ordered?console_order=01JE…&status=completed
]);

// routes/web.php
Route::get('/ordered', function (Request $request) use ($console, $team) {
    // never trust the query: read the order (completed once Anikeen ID confirmed it)
    $order = $console->orders($team)->find($request->query('console_order'));

    return view('ordered', ['order' => $order]);
})->name('ordered');
```

Catalog filters (all optional, applied by the Console; families left without products are left
out): `family` (alias or list), `gpu` (`true`/`false`), `gpu_vendor`, `gpu_model` (substring),
`gpu_mode` (`shared`/`exclusive`), `gpu_min_vram_gb`, `gpu_min_count`, `min_vcores`,
`min_memory_mb`, `min_disk_gb`, `min_price` / `max_price` (each product's `monthly_price`: what a
month costs at most, net, at the team's price) and `sort` (`price`/`-price`). A bad value throws
`ValidationFailedException`.

### Tokens

- `forUser($user)` reads the tokens `anikeen/id` stores on the user. A token that expires within a
  minute is refreshed through `AnikeenId::refreshToken()` before the request; a `401` refreshes it
  and sends the request once more. The new pair is saved on the user (only those two columns).
  Refreshes are locked per user through the cache, and a pair another process stored meanwhile is
  used instead of refreshing again (refresh tokens are single-use).
- When the refresh fails, an `AuthenticationException` asks you to send the user through the
  Anikeen ID login again. A user without any token throws `anikeen/id`'s
  `RequestRequiresAuthenticationException`.
- `AnikeenConsole::withToken($token)` uses a fixed token (e.g. a personal access token in a
  script) and never refreshes it.
- The shared `AnikeenConsole` instance keeps no token: `forUser()` and `withToken()` return copies.

### Orders

```php
$orders = $console->orders($team);           // a Team or its id

$order = $orders->create($items, ['tos_accepted' => true, 'withdrawal_waiver' => true, 'coupon_code' => 'SPRING']);
$order->idempotencyKey;                      // the Idempotency-Key that was sent (a new UUID per create)

// your own key, e.g. the id of your own order: a retry after a crash returns the first order
$order = $orders->withIdempotencyKey("gleem-order-{$localOrder->id}")->create($items, ['tos_accepted' => true]);

$order->isOpen();                            // status: pending, open, completed, cancelled, expired, failed
$order = $order->refresh();                  // GET …/orders/{order}; asks Anikeen ID about an open order
$order->products();                          // [{type: "container", id: "01JD…"}, …] once provisioned

foreach ($orders->list(['status' => 'open']) as $order) { … }
$order = $orders->find($orderId);
```

The retry after a token refresh sends the same Idempotency-Key, so an order is never placed twice.
Keys live 24 hours per team; the same key with another body is refused
(`ValidationFailedException`, `error` `idempotency_key_reused`). At most 10 orders per minute and
team (`RateLimitException`).

### Containers

```php
$containers = $console->containers();

foreach ($containers->list() as $container) { … }     // without status
$container = $containers->find($id);                   // with status: running, stopped (null: unknown)

$container->power('reboot');                           // start, stop, shutdown, reboot → 202
$container->reinstall($templateId, $sshKeyId);         // all data and snapshots are lost
$container->update(['name' => 'game-2']);              // hostname, inside after the next restart
$container->delete();                                  // confirmed with its name; kept 14 days

$container->backups()->list();                         // or $containers->backups($id)
$container->backups()->create('before update');
$container->backups()->restore($backupId);
$container->backups()->delete($backupId);

$container->snapshots()->list();
$container->snapshots()->create('before_update', 'Before the update');
$container->snapshots()->rollback('before_update');
$container->snapshots()->delete('before_update');
```

Operations answering `202` (power, reinstall, backups, snapshots) run in the background; read the
container again, or wait for the `product.updated` webhook.

### Lists

Lists return a `BaseCollection` of resources: iterate it, `count()`, `first()`, `all()`,
`total()`, `currentPage()`, `lastPage()`, `hasMorePages()`, `nextPage()`, `previousPage()`;
`paginate()` gives the raw `Result`.

### Resources

Resources expose the API's fields as properties (nested objects as `stdClass`, unknown fields read
as `null`), `toArray()` and JSON serialization. `$resource->result` holds the response.

## Errors

Failed requests throw a subclass of `Anikeen\Console\Exceptions\ApiException` (which extends
`anikeen/id`'s `ApiException`): `status`, `error` (the Console's code), `getMessage()`, `body()`,
`isTemporary()` (unreachable, 5xx or 429).

| Exception | When |
|-----------|------|
| `AuthenticationException` | `401`, also after a failed refresh: sign the user in again |
| `ClientNotAllowedException` | `401 client_not_allowed`: the Console does not accept your app's tokens (not trusted) |
| `MissingScopeException` | `403 missing_scope`; `scope()` names the missing scope |
| `NotFoundException` | `404`: no such object, or not in your app team |
| `AccountConflictException` | `409 account_conflict`: another Console account uses the user's e-mail address |
| `ValidationFailedException` | `422`; `errors()` per field (e.g. `items.0.attributes.location_id`), `errorFor($field)` |
| `RateLimitException` | `429`; `retryAfter` in seconds |
| `ApiException` | everything else, e.g. `403 email_not_verified`, `403 owner_only`, `409` (deletion protection, suspended), `502 checkout_unavailable`; `status` `0` when the Console was not reachable |

## Webhooks

The Console tells your app when an order went through and a product is ready. Webhook endpoints
are set up by Anikeen on request (URL, event types); you get the signing secret `whsec_…`.

Register the route (no CSRF protection needed, the macro excludes it):

```php
// routes/web.php or routes/api.php
Route::anikeenConsoleWebhooks('webhooks/anikeen-console');
```

It verifies the `Anikeen-Signature` with `services.anikeen_console.webhook_secret` (`anikeen/id`'s
`Webhook::constructEvent()`, the same format as Anikeen ID's webhooks; `400` otherwise), ignores
events it has already handled (by event id, remembered 3 days in the cache) and dispatches
`Anikeen\Console\Events\WebhookReceived` for every event plus a typed event:

| Type (`Webhooks\EventType`) | Event | `$event->object` |
|------|-------|----------|
| `order.completed` | `OrderCompleted` | `Order`: checkout confirmed, provisioning started |
| `order.failed` | `OrderFailed` | `Order`: checkout cancelled, expired or could not be opened |
| `product.ready` | `ProductReady` | `Container` (or `Product`): built; `status` is the live status (container: `running`) when the Console could read it, else `active` |
| `product.failed` | `ProductFailed` | `Product`: building one order item failed (refunded); `id` is `null`, with `order_id`, `order_item_id`, `product_id`, `error` |
| `product.updated` | `ProductUpdated` | `Product`: `data.reason` is `billing_suspended`, `billing_resumed`, `rescaled` or `reinstalled` |
| `product.deleted` | `ProductDeleted` | `Product`: deleted; `data.reason` `contract_ended` when the contract ended in Anikeen ID |
| `team.released` | `TeamReleased` | `Team`: the user removed your app in Anikeen ID; the last event for that team |
| `webhook.test` | `WebhookTest` | `null` (see `$event->data`) |

Every event has `id`, `type`, `created`, `data` (the whole `data` as an array) and `object`.

```php
use Anikeen\Console\Events\ProductReady;

Event::listen(function (ProductReady $event) {
    $server = GameServer::where('console_id', $event->object->id)->first();
    $server?->markReady($event->object->status);
});
```

Listeners may be queued. If a listener throws, the endpoint answers `500` and forgets the event
id, so the Console's retry gets through. Resources from webhooks have no API client; bind one to
act on them: `$event->object->withClient(AnikeenConsole::forUser($user))->power('start')`.

## Testing your app

```php
use Anikeen\Console\Facades\AnikeenConsole;
use Anikeen\Console\Testing\AnikeenConsoleFake;
use Anikeen\Console\Testing\RecordedRequest;

$fake = AnikeenConsole::fake([
    // "METHOD path" or "path" (relative to the base URL, * matches anything) => response
    'GET containers/01JCT' => ['id' => '01JCT', 'name' => 'game-1', 'status' => 'running'],
    'POST containers/*/power' => fn (RecordedRequest $request) => ['message' => "Doing {$request->json['action']}"],
    'GET catalog' => AnikeenConsoleFake::response(['message' => 'Missing scope.', 'error' => 'missing_scope'], 403),
]);

$this->post('/servers', [...]);   // your code calls AnikeenConsole::forUser($user)->...

$fake->assertOrdered(fn (array $items, array $body, string $team) => $items[0]['family'] === 'container');
$fake->assertNothingOrdered();
$fake->assertSent('POST containers/*/power');
$fake->assertNotSent(fn (RecordedRequest $request) => $request->method === 'DELETE');
$fake->recorded();                // every RecordedRequest (method, path, query, json, header())
```

Arrays become JSON responses (`201` for orders, else `200`). Without a matching response the fake
answers with defaults: `GET app-team` a team (`team_fake`), `POST teams/{team}/orders` an open
order with a `checkout_url`, empty lists, `202` for other `POST`s, `204` for deleting a container,
`200 {}` otherwise. Users need no tokens with the fake and nothing is refreshed.

Webhooks, signed with your configured secret and sent through your app:

```php
use Anikeen\Console\Webhooks\EventType;

AnikeenConsole::webhookRequest(EventType::PRODUCT_READY, [
    'id' => '01JCT', 'family' => 'container', 'team_id' => 'team_fake', 'status' => 'running',
])->send()->assertOk();
```

`webhookRequest()` returns a `WebhookRequest` with the `event`, the signed `body` and the `headers`;
`send($uri)` posts it to the webhook route, `toRequest($uri)` gives an `Illuminate\Http\Request`.

## Development

```shell
composer install
composer test
```

See `CLAUDE.md` for the layout and conventions. A change to the Console API that apps use (new
endpoint, field or event) must be mirrored here; the Console's `docs/API.md` is the reference.
