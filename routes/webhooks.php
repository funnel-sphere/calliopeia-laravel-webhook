<?php

use FunnelSphere\CalliopeiaWebhook\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware((array) config('calliopeia-webhook.route_middleware', ['api']))
    ->post((string) config('calliopeia-webhook.path', 'api/calliopeia/webhooks'), WebhookController::class)
    ->name('calliopeia.webhooks.receive');
