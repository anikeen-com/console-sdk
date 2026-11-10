# Changelog

## 1.0.0 (2026-11-10)

The first release, for apps acting in the Anikeen Console for their users. Needs `anikeen/id` 3.6
and a Console with app tokens, `GET v1/app-team` and app webhooks.

### Added

- Container LXC options: the order attribute `options` (`{features: [fuse, keyctl], devices: [tun]}`,
  e.g. for rootless Podman inside the container), documented in `Orders::create()` and the README;
  `Container::$options`, `hasFeature()`, `hasDevice()` and the constants `FEATURE_FUSE`,
  `FEATURE_KEYCTL`, `DEVICE_TUN`; `reinstall()` takes new options (null keeps them).
- Works with Guzzle 7.8+ and 8 (`guzzlehttp/guzzle: ^7.8|^8.0`), so it installs in a new Laravel 13 app.
- `AnikeenConsole::forUser($user)`: requests with the user's stored Anikeen ID token, refreshed
  through `anikeen/id` when it expires or the Console answers `401` (the new pair is saved, the
  request sent once more; locked per user). `withToken($token)` for a fixed token.
- Production and staging base URLs (`https://console.anikeen.com/api/v1`,
  `https://staging.console.anikeen.com/api/v1`; `services.anikeen_console.mode` / `base_url`).
- `appTeam()` (`GET v1/app-team`) and `catalog($team)` with helpers per family.
- `orders($team)->create($items, ['return_url' => …])`: send the user back to your app after the
  checkout (app tokens only); the Console appends `console_order` and `status`.
- `catalog($team, $filters)`: server-side filters (`gpu`, `gpu_vendor`, `gpu_model`, `gpu_mode`,
  `gpu_min_vram_gb`, `gpu_min_count`, `min_vcores`, `min_memory_mb`, `min_disk_gb`, `min_price`,
  `max_price` on `monthly_price`, `family`, `sort`); booleans sent as `true`/`false`, lists comma
  separated.
- `orders($team)`: `create()` with an automatic Idempotency-Key (kept for the retry after a token
  refresh), `withIdempotencyKey()`, `list()`, `find()`; `Order` with `checkout_url`, `refresh()`
  and `products()`.
- `containers()`: list, find, update, delete, power, reinstall, backups (list, create, restore,
  delete) and snapshots (list, create, rollback, delete), also as methods of `Container`.
- Paginated lists (`BaseCollection`) for both pagination formats of the Console.
- Exceptions by status and error code: `AuthenticationException`, `ClientNotAllowedException`,
  `MissingScopeException` (`scope()`), `NotFoundException`, `AccountConflictException`,
  `ValidationFailedException` (`errors()`), `RateLimitException` (`retryAfter`), `ApiException`.
- Webhooks: `Route::anikeenConsoleWebhooks()` verifies the signature (`anikeen/id`'s
  `Webhook::constructEvent()`), drops replays by event id and dispatches `WebhookReceived` plus
  `OrderCompleted`, `OrderFailed`, `ProductReady`, `ProductFailed`, `ProductUpdated`,
  `ProductDeleted`, `TeamReleased` and `WebhookTest`; `Webhooks\EventType` constants.
- Testing: `AnikeenConsole::fake()` with `assertOrdered()`, `assertNothingOrdered()`,
  `assertSent()`, `assertNotSent()`; `AnikeenConsole::webhookRequest()` for signed webhook requests.
