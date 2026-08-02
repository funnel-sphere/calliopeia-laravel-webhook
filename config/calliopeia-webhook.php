<?php

use FunnelSphere\CalliopeiaWebhook\Handlers\DispatchWebhookReceivedEvent;
use FunnelSphere\CalliopeiaWebhook\Policies\AcceptAllWebhooks;

return [
    'enabled' => env('CALLIOPEIA_WEBHOOK_ENABLED', true),
    'path' => env('CALLIOPEIA_WEBHOOK_PATH', 'api/calliopeia/webhooks'),
    'route_middleware' => ['api', 'throttle:calliopeia-webhooks'],
    'rate_limit_per_minute' => (int) env('CALLIOPEIA_WEBHOOK_RATE_LIMIT', 120),

    // AUTO, SUMMARY_CALLBACK_V1, or EVENT_ENVELOPE_V1.
    'payload_profile' => env('CALLIOPEIA_WEBHOOK_PAYLOAD_PROFILE', 'AUTO'),
    'max_body_bytes' => (int) env('CALLIOPEIA_WEBHOOK_MAX_BODY_BYTES', 184_320),
    'timestamp_tolerance_seconds' => (int) env('CALLIOPEIA_WEBHOOK_TIMESTAMP_TOLERANCE', 600),

    'auth' => [
        // BEARER, SIGNED_JWT, RSA_SIGNATURE, BODY_ACCESS_TOKEN, or NONE.
        'mode' => env('CALLIOPEIA_WEBHOOK_AUTH_MODE', 'BEARER'),
        'bearer_token' => env('CALLIOPEIA_WEBHOOK_TOKEN'),
        'body_access_token' => env('CALLIOPEIA_WEBHOOK_TOKEN'),
        'allow_none' => env('CALLIOPEIA_WEBHOOK_ALLOW_NONE', false),

        // Public key issued by Calliopeia. A file path is preferable for multiline PEM data.
        'public_key' => env('CALLIOPEIA_WEBHOOK_PUBLIC_KEY'),
        'public_key_path' => env('CALLIOPEIA_WEBHOOK_PUBLIC_KEY_PATH'),
        'key_id' => env('CALLIOPEIA_WEBHOOK_KEY_ID'),
        'issuer' => env('CALLIOPEIA_WEBHOOK_ISSUER', 'calliopeia'),
        'audience' => env('CALLIOPEIA_WEBHOOK_AUDIENCE'),
        'clock_skew_seconds' => (int) env('CALLIOPEIA_WEBHOOK_CLOCK_SKEW', 30),
    ],

    'queue' => [
        'connection' => env('CALLIOPEIA_WEBHOOK_QUEUE_CONNECTION'),
        'name' => env('CALLIOPEIA_WEBHOOK_QUEUE', 'calliopeia-webhooks'),
        'processing_lock_seconds' => (int) env('CALLIOPEIA_WEBHOOK_PROCESSING_LOCK', 300),
    ],

    // Replace either class in the published config for application-specific behavior.
    'acceptance_policy' => AcceptAllWebhooks::class,
    'handler' => DispatchWebhookReceivedEvent::class,
];
