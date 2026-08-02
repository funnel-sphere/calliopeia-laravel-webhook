<?php

namespace FunnelSphere\CalliopeiaWebhook\Tests;

use FunnelSphere\CalliopeiaWebhook\CalliopeiaWebhookServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [CalliopeiaWebhookServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('c', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('calliopeia-webhook.auth.mode', 'BEARER');
        $app['config']->set('calliopeia-webhook.auth.bearer_token', 'test-webhook-token');
        $app['config']->set('calliopeia-webhook.timestamp_tolerance_seconds', 600);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }

    /** @return array<string, string> */
    protected function webhookHeaders(string $eventId = 'event-001', ?int $timestamp = null): array
    {
        return [
            'Authorization' => 'Bearer test-webhook-token',
            'Content-Type' => 'application/json',
            'User-Agent' => 'Calliopeia-Webhook/1.0',
            'X-Calliopeia-Event-Id' => $eventId,
            'X-Calliopeia-Timestamp' => (string) ($timestamp ?? now()->getTimestamp()),
            'X-Calliopeia-Attempt' => '1',
        ];
    }

    /** @return array<string, mixed> */
    protected function summaryPayload(int $summaryId = 123, string $body = '肩まわりの状態を確認した。'): array
    {
        return [
            'summary_id' => $summaryId,
            'status' => 'succeeded',
            'summarized_json' => [
                ['title' => '今回の内容', 'body' => $body],
                ['title' => '次回来店', 'body' => '次回予約は未定。'],
            ],
            'error_message' => null,
        ];
    }

    /** @return array<string, mixed> */
    protected function eventPayload(string $eventId = 'event-envelope-001'): array
    {
        return [
            'version' => '2026-07-25',
            'event_id' => $eventId,
            'event_type' => 'audio.summary.completed',
            'occurred_at' => now()->toIso8601String(),
            'job_id' => 'job-001',
            'summary_id' => 'summary-001',
            'idempotency_key' => 'partner-request-001',
            'user_id' => 'user-001',
            'shop_id' => 'shop-001',
            'customer_id' => 'customer-001',
            'karte_id' => 'karte-001',
            'status' => 'COMPLETED',
            'processing' => [
                'extraction_level' => 'DETAILED',
                'extraction_effort' => 'MAX',
                'audit_mode' => 'ENABLED',
                'audit_effort' => 'STANDARD',
                'audit_strategy' => 'ATOMIC_CLAIMS',
                'audit_batch_size' => 8,
            ],
            'result' => [
                'text' => '要約結果',
                'data' => [['title' => '今回の内容', 'body' => '施術内容を確認した。']],
            ],
            'error' => null,
            'review' => null,
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    protected function serverHeaders(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $normalized = strtoupper(str_replace('-', '_', $name));
            $server[$normalized === 'CONTENT_TYPE' ? 'CONTENT_TYPE' : 'HTTP_'.$normalized] = $value;
        }

        return $server;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $claims
     * @return array{string, string}
     */
    protected function signedJwt(array $claims): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $header = $this->base64Url(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => 'test-key',
        ], JSON_THROW_ON_ERROR));
        $body = $this->base64Url(json_encode($claims, JSON_THROW_ON_ERROR));
        self::assertTrue(openssl_sign($header.'.'.$body, $signature, $privateKey, OPENSSL_ALGO_SHA256));

        return [$header.'.'.$body.'.'.$this->base64Url($signature), $details['key']];
    }

    /** @return array{string, string} */
    protected function rsaKeyPair(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        return [$privateKey, $details['key']];
    }
}
