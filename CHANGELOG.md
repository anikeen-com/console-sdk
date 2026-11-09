# Changelog

## Unreleased

Planned as 0.1.0: the first release for apps acting in the Anikeen Console for their users (first
user: Gleem). Needs `anikeen/id` 3.6 and a Console release with app tokens, `GET v1/app-team` and
app webhooks (on staging first).

### Added

- `AnikeenConsole::forUser($user)`: requests with the user's stored Anikeen ID token, refreshed
  through `anikeen/id` when it expires or the Console answers `401` (the new pair is saved, the
  request sent once more; locked per user). `withToken($token)` for a fixed token.
- Production and staging base URLs (`services.anikeen_console.mode` / `base_url`).
- `appTeam()` (`GET v1/app-team`) and `catalog($team)` with helpers per family.
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
