<?php

namespace FunnelSphere\CalliopeiaWebhook;

use FunnelSphere\CalliopeiaWebhook\Contracts\WebhookAcceptancePolicy;
use FunnelSphere\CalliopeiaWebhook\Contracts\WebhookHandler;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class CalliopeiaWebhookServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/calliopeia-webhook.php', 'calliopeia-webhook');

        $this->app->bind(WebhookAcceptancePolicy::class, function ($app): WebhookAcceptancePolicy {
            $class = (string) config('calliopeia-webhook.acceptance_policy');

            return $app->make($class);
        });
        $this->app->bind(WebhookHandler::class, function ($app): WebhookHandler {
            $class = (string) config('calliopeia-webhook.handler');

            return $app->make($class);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/calliopeia-webhook.php' => config_path('calliopeia-webhook.php'),
        ], 'calliopeia-webhook-config');

        RateLimiter::for('calliopeia-webhooks', function (Request $request): Limit {
            $limit = max(1, (int) config('calliopeia-webhook.rate_limit_per_minute', 120));

            return Limit::perMinute($limit)->by($request->ip() ?: 'unknown');
        });

        if ((bool) config('calliopeia-webhook.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
        }
    }
}
