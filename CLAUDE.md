# CLAUDE.md

Guidance for Claude Code (and other contributors) working in this repository.

## What this is

`anikeen/console` is the PHP client for the Anikeen Console API, shipped as a Laravel package
(Laravel 12–13, PHP 8.2+). Apps (first: Gleem) act in the Console for their users with the user's
own Anikeen ID token: app team, catalog, orders, containers, webhooks. It depends on `anikeen/id`
for the tokens (fields, refresh), the webhook signature (`Webhook::constructEvent()`) and the
base exception. The repo is public (on Packagist): never commit secrets,
internal hostnames or customer data.

**A change to the Console API that apps use (new endpoint, field, error code or event) must be
mirrored here.** The reference is the Console's `docs/API.md` (section "Apps acting for users"),
the routes are in the Console's `routes/api.php` and `packages/*/routes/api.php`.

## Commands

```shell
composer install
composer test                                   # vendor/bin/phpunit
vendor/bin/phpunit --filter OrdersTest
```

CI (`.github/workflows/tests.yml`) runs PHPUnit on PHP 8.3 and 8.4.

## Layout

- `src/AnikeenConsole.php` – the client (singleton, facade `AnikeenConsole`). Reads
  `config('services.anikeen_console.*')`: `mode=staging` switches to the staging base URL unless
  `base_url` is set. The shared instance has no token; `forUser()` / `withToken()` return clones.
  `send()` returns a `Result` (also for 4xx/5xx) and does the refresh + one retry on `401`;
  `call()` throws `ApiException::from($result)` on failure. Endpoints and resources use `call()`.
- `src/Result.php` – one response; reads both list formats (API resources with `meta`/`links`,
  Laravel's plain paginator) into `meta`, `links` and `anikeen/id`'s `Paginator`.
- `src/Endpoints/` – one class per API area (`Orders`, `Containers`, `ContainerBackups`,
  `ContainerSnapshots`). They send nothing until a method is called.
- `src/Resources/` – `BaseResource` (fields as dynamic properties, `withClient()` for actions,
  serializable without the client) and `BaseCollection` (a page, `nextPage()`); `Product::make()`
  picks the family's class (`Product::FAMILIES`).
- `src/Exceptions/` – `ApiException` extends `anikeen/id`'s `ApiException`; `from()` maps status and
  `error` code to the subclasses.
- `src/Webhooks/EventType.php`, `src/Http/Controllers/WebhookController.php`, `src/Events/` – the
  webhook route (`Route::anikeenConsoleWebhooks()`, macro in the service provider), verification,
  replay protection (cache, 3 days) and the typed events (`WebhookController::EVENTS`).
- `src/Testing/` – `AnikeenConsoleFake` (`AnikeenConsole::fake()`), `RecordedRequest`,
  `WebhookRequest` (`AnikeenConsole::webhookRequest()`).

## Conventions

- **New endpoints:** a method on the matching `Endpoints` class (or a new class plus an accessor on
  `AnikeenConsole`), document request attributes with an `array{…}` PHPDoc shape, add a resource
  class when the object has actions, a test asserting method, path, query and JSON body, a README
  example and a CHANGELOG entry. A new product family follows the container pattern and adds its
  class to `Product::FAMILIES`.
- **New webhook events:** constant in `Webhooks\EventType`, an event class in `src/Events`, the
  mapping in `WebhookController::EVENTS`, README table.
- **New error codes** apps branch on: a subclass of `ApiException` and a line in `ApiException::from()`.
- **Tests:** extend `Anikeen\Console\Tests\TestCase` (Orchestra Testbench, SQLite in memory with a
  `users` table). No live API: queue Console responses with `$this->respond($status, $body)` and
  Anikeen ID responses (token refresh) on `$this->idHttp`; assert with `$this->assertRequest()`,
  `$this->sent` / `$this->idSent`. `self::jwt()` makes an unsigned token with `exp` and `scopes`.
- **Compatibility:** a library used by several apps. Keep public signatures backwards compatible;
  add optional parameters instead of changing existing ones.
- **Releases:** tagged only (no dev-main); `0.x` while the Console side is on staging, `1.0` after
  Console production. `composer.lock` is not committed (library).
- Code, comments, docs and commit messages in English. Commit subjects are short, imperative,
  sentence case. Work happens on feature branches merged via PR into `main`.
