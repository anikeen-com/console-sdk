<?php

namespace Anikeen\Console\Providers;

use Anikeen\Console\AnikeenConsole;
use Anikeen\Console\Http\Controllers\WebhookController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AnikeenConsoleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AnikeenConsole::class, fn () => new AnikeenConsole());
    }

    public function boot(): void
    {
        /*
         * Route::anikeenConsoleWebhooks('webhooks/anikeen-console'): the endpoint for the Console's
         * webhooks (POST, without CSRF protection: requests are verified by their signature).
         */
        Route::macro('anikeenConsoleWebhooks', function (string $uri = 'webhooks/anikeen-console'): RoutingRoute {
            AnikeenConsole::useWebhookUri($uri);

            // Laravel 13's web group checks with PreventRequestForgery, older ones with ValidateCsrfToken
            return Route::post($uri, WebhookController::class)
                ->withoutMiddleware(array_values(array_filter([
                    'Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery',
                    ValidateCsrfToken::class,
                    VerifyCsrfToken::class,
                ], 'class_exists')))
                ->name('anikeen-console.webhooks');
        });
    }

    /**
     * @return list<class-string>
     */
    public function provides(): array
    {
        return [AnikeenConsole::class];
    }
}
