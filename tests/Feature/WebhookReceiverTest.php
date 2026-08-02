<?php

namespace FunnelSphere\CalliopeiaWebhook\Tests\Feature;

use FunnelSphere\CalliopeiaWebhook\Contracts\WebhookAcceptancePolicy;
use FunnelSphere\CalliopeiaWebhook\Data\IncomingWebhook;
use FunnelSphere\CalliopeiaWebhook\Events\CalliopeiaWebhookReceived;
use FunnelSphere\CalliopeiaWebhook\Exceptions\WebhookRequestException;
use FunnelSphere\CalliopeiaWebhook\Jobs\ProcessWebhookReceipt;
use FunnelSphere\CalliopeiaWebhook\Models\WebhookReceipt;
use FunnelSphere\CalliopeiaWebhook\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

final class WebhookReceiverTest extends TestCase
{
    public function test_default_sync_handler_dispatches_the_laravel_event(): void
    {
        Event::fake([CalliopeiaWebhookReceived::class]);

        $this->postJson(
            '/api/calliopeia/webhooks',
            $this->summaryPayload(122),
            $this->webhookHeaders('event-sync-handler'),
        )->assertOk();

        $receipt = WebhookReceipt::query()->sole();
        self::assertSame('processed', $receipt->processing_state);
        Event::assertDispatched(
            CalliopeiaWebhookReceived::class,
            fn (CalliopeiaWebhookReceived $event): bool => $event->receiptId === $receipt->getKey(),
        );
    }

    public function test_accepts_summary_callback_and_encrypts_persisted_payload(): void
    {
        Queue::fake();

        $response = $this->postJson(
            '/api/calliopeia/webhooks',
            $this->summaryPayload(),
            $this->webhookHeaders(),
        );

        $response->assertOk()->assertExactJson([
            'accepted' => true,
            'duplicate' => false,
            'event_id' => 'event-001',
        ]);
        $receipt = WebhookReceipt::query()->sole();
        self::assertSame('SUMMARY_CALLBACK_V1', $receipt->profile);
        self::assertSame('123', $receipt->summary_id);
        self::assertSame('肩まわりの状態を確認した。', $receipt->payload['summarized_json'][0]['body']);
        $rawPayload = DB::table('calliopeia_webhook_receipts')->value('payload');
        self::assertIsString($rawPayload);
        self::assertStringNotContainsString('肩まわり', $rawPayload);
        Queue::assertPushed(ProcessWebhookReceipt::class, 1);
    }

    public function test_returns_200_without_second_job_for_identical_duplicate(): void
    {
        Queue::fake();
        $headers = $this->webhookHeaders('event-duplicate');

        $this->postJson('/api/calliopeia/webhooks', $this->summaryPayload(), $headers)->assertOk();
        $this->postJson('/api/calliopeia/webhooks', $this->summaryPayload(), $headers)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        self::assertSame(1, WebhookReceipt::query()->count());
        Queue::assertPushed(ProcessWebhookReceipt::class, 1);
    }

    public function test_rejects_same_summary_id_with_different_content(): void
    {
        Queue::fake();
        $this->postJson(
            '/api/calliopeia/webhooks',
            $this->summaryPayload(123, '最初の内容'),
            $this->webhookHeaders('event-a'),
        )->assertOk();

        $this->postJson(
            '/api/calliopeia/webhooks',
            $this->summaryPayload(123, '改変された内容'),
            $this->webhookHeaders('event-b'),
        )->assertStatus(422)->assertJsonPath('error', 'idempotency_conflict');

        self::assertSame(1, WebhookReceipt::query()->count());
    }

    public function test_accepts_failed_summary_callback(): void
    {
        Queue::fake();
        $payload = [
            'summary_id' => 987,
            'status' => 'failed',
            'error_message' => '音声の解析に失敗しました。時間をおいて再度お試しください。',
        ];

        $this->postJson('/api/calliopeia/webhooks', $payload, $this->webhookHeaders('event-failed'))
            ->assertOk();

        self::assertSame('failed', WebhookReceipt::query()->sole()->payload['status']);
    }

    public function test_rejects_bad_bearer_stale_timestamp_and_invalid_contract(): void
    {
        Queue::fake();
        $badAuth = $this->webhookHeaders('event-auth');
        $badAuth['Authorization'] = 'Bearer incorrect';
        $this->postJson('/api/calliopeia/webhooks', $this->summaryPayload(), $badAuth)
            ->assertStatus(401)->assertJsonPath('error', 'unauthorized');

        $this->postJson(
            '/api/calliopeia/webhooks',
            $this->summaryPayload(),
            $this->webhookHeaders('event-stale', now()->subHour()->getTimestamp()),
        )->assertStatus(401)->assertJsonPath('error', 'unauthorized');

        $invalid = $this->summaryPayload();
        $invalid['summarized_json'][0]['body'] = '<script>invalid</script>';
        $this->postJson('/api/calliopeia/webhooks', $invalid, $this->webhookHeaders('event-invalid'))
            ->assertStatus(422)->assertJsonPath('error', 'invalid_webhook');

        self::assertSame(0, WebhookReceipt::query()->count());
    }

    public function test_rejects_malformed_oversized_and_non_json_requests(): void
    {
        Queue::fake();
        $headers = $this->serverHeaders($this->webhookHeaders('event-malformed'));
        $this->call('POST', '/api/calliopeia/webhooks', [], [], [], $headers, '{bad json')
            ->assertStatus(422);

        config()->set('calliopeia-webhook.max_body_bytes', 16);
        $body = json_encode($this->summaryPayload(), JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/calliopeia/webhooks', [], [], [], $headers, $body)
            ->assertStatus(413);

        $nonJsonHeaders = $headers;
        $nonJsonHeaders['CONTENT_TYPE'] = 'text/plain';
        $this->call('POST', '/api/calliopeia/webhooks', [], [], [], $nonJsonHeaders, '{}')
            ->assertStatus(415);
    }

    public function test_accepts_event_envelope_and_requires_matching_event_id(): void
    {
        Queue::fake();
        $eventId = 'event-envelope-001';
        $this->postJson(
            '/api/calliopeia/webhooks',
            $this->eventPayload($eventId),
            $this->webhookHeaders($eventId),
        )->assertOk();

        $receipt = WebhookReceipt::query()->sole();
        self::assertSame('EVENT_ENVELOPE_V1', $receipt->profile);
        self::assertSame('summary-001', $receipt->summary_id);

        $this->postJson(
            '/api/calliopeia/webhooks',
            $this->eventPayload('body-event-id'),
            $this->webhookHeaders('header-event-id'),
        )->assertStatus(422);
    }

    public function test_accepts_all_terminal_event_envelope_variants(): void
    {
        Queue::fake();
        $variants = [
            'REVIEW_REQUIRED' => 'audio.summary.review_required',
            'AUDIT_INCOMPLETE' => 'audio.summary.audit_incomplete',
            'ERROR' => 'audio.summary.failed',
        ];

        foreach ($variants as $status => $eventType) {
            $eventId = 'event-'.strtolower($status);
            $payload = $this->eventPayload($eventId);
            $payload['status'] = $status;
            $payload['event_type'] = $eventType;
            $payload['result'] = null;
            if ($status === 'ERROR') {
                $payload['error'] = ['message' => '要約処理に失敗しました'];
                $payload['review'] = null;
            } else {
                $payload['error'] = null;
                $payload['review'] = [
                    'state' => $status,
                    'message' => '転写根拠監査により結果提供を停止しました',
                ];
            }

            $this->postJson(
                '/api/calliopeia/webhooks',
                $payload,
                $this->webhookHeaders($eventId),
            )->assertOk();
        }

        self::assertSame(3, WebhookReceipt::query()->count());
    }

    public function test_body_access_token_is_verified_and_never_persisted(): void
    {
        Queue::fake();
        config()->set('calliopeia-webhook.auth.mode', 'BODY_ACCESS_TOKEN');
        config()->set('calliopeia-webhook.auth.body_access_token', 'body-secret');
        $eventId = 'event-body-token';
        $payload = $this->eventPayload($eventId);
        $payload['access_token'] = 'body-secret';
        $headers = $this->webhookHeaders($eventId);
        unset($headers['Authorization']);

        $invalid = $payload;
        $invalid['access_token'] = 'incorrect';
        $this->postJson('/api/calliopeia/webhooks', $invalid, $headers)->assertStatus(401);

        $this->postJson('/api/calliopeia/webhooks', $payload, $headers)->assertOk();

        $receipt = WebhookReceipt::query()->sole();
        self::assertArrayNotHasKey('access_token', $receipt->payload);
        $rawPayload = (string) DB::table('calliopeia_webhook_receipts')->value('payload');
        self::assertStringNotContainsString('body-secret', $rawPayload);
    }

    public function test_accepts_valid_rsa_signature_and_rejects_modified_signature(): void
    {
        Queue::fake();
        [$privateKey, $publicKey] = $this->rsaKeyPair();
        config()->set('calliopeia-webhook.auth.mode', 'RSA_SIGNATURE');
        config()->set('calliopeia-webhook.auth.public_key', $publicKey);
        config()->set('calliopeia-webhook.auth.key_id', 'rsa-key-1');
        $eventId = 'event-rsa';
        $body = json_encode($this->summaryPayload(300), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $headers = $this->webhookHeaders($eventId);
        unset($headers['Authorization']);
        $headers['X-Calliopeia-Key-Id'] = 'rsa-key-1';
        self::assertTrue(openssl_sign(
            $headers['X-Calliopeia-Timestamp'].'.'.$body,
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA256,
        ));
        $headers['X-Calliopeia-Signature'] = base64_encode($signature);

        $this->call(
            'POST',
            '/api/calliopeia/webhooks',
            [],
            [],
            [],
            $this->serverHeaders($headers),
            $body,
        )->assertOk();

        $headers['X-Calliopeia-Signature'] = base64_encode('modified');
        $headers['X-Calliopeia-Event-Id'] = 'event-rsa-invalid';
        $this->call(
            'POST',
            '/api/calliopeia/webhooks',
            [],
            [],
            [],
            $this->serverHeaders($headers),
            $body,
        )->assertStatus(401);
    }

    public function test_accepts_signed_jwt_with_bound_event_and_summary_claims(): void
    {
        Queue::fake();
        config()->set('calliopeia-webhook.auth.mode', 'SIGNED_JWT');
        config()->set('calliopeia-webhook.auth.key_id', 'test-key');
        config()->set('calliopeia-webhook.auth.audience', 'https://partner.example/callback');
        $eventId = 'event-jwt';
        [$jwt, $publicKey] = $this->signedJwt([
            'iss' => 'calliopeia',
            'aud' => 'https://partner.example/callback',
            'sub' => 'job-001',
            'iat' => now()->getTimestamp(),
            'exp' => now()->addMinutes(5)->getTimestamp(),
            'jti' => $eventId,
            'summary_id' => 456,
        ]);
        config()->set('calliopeia-webhook.auth.public_key', $publicKey);
        $headers = $this->webhookHeaders($eventId);
        $headers['Authorization'] = 'Bearer '.$jwt;

        $this->postJson('/api/calliopeia/webhooks', $this->summaryPayload(456), $headers)
            ->assertOk();
    }

    public function test_application_policy_can_return_unknown_summary_as_404(): void
    {
        Queue::fake();
        config()->set('calliopeia-webhook.acceptance_policy', RejectSummaryPolicy::class);

        $this->postJson(
            '/api/calliopeia/webhooks',
            $this->summaryPayload(404),
            $this->webhookHeaders('event-missing'),
        )->assertStatus(404)->assertJsonPath('error', 'unknown_summary');

        self::assertSame(0, WebhookReceipt::query()->count());
    }

    public function test_none_auth_fails_closed_until_explicitly_enabled(): void
    {
        Queue::fake();
        config()->set('calliopeia-webhook.auth.mode', 'NONE');
        $headers = $this->webhookHeaders('event-none');
        unset($headers['Authorization']);

        $this->postJson('/api/calliopeia/webhooks', $this->summaryPayload(600), $headers)
            ->assertStatus(500)
            ->assertJsonPath('error', 'receiver_misconfigured');

        config()->set('calliopeia-webhook.auth.allow_none', true);
        $this->postJson('/api/calliopeia/webhooks', $this->summaryPayload(600), $headers)
            ->assertOk();
    }

    public function test_route_rate_limit_returns_429_with_retry_after(): void
    {
        Queue::fake();
        config()->set('calliopeia-webhook.rate_limit_per_minute', 1);

        $this->postJson(
            '/api/calliopeia/webhooks',
            $this->summaryPayload(701),
            $this->webhookHeaders('event-rate-a'),
        )->assertOk();

        $response = $this->postJson(
            '/api/calliopeia/webhooks',
            $this->summaryPayload(702),
            $this->webhookHeaders('event-rate-b'),
        );
        $response->assertStatus(429);
        self::assertNotNull($response->headers->get('Retry-After'));
    }
}

final class RejectSummaryPolicy implements WebhookAcceptancePolicy
{
    public function assertAcceptable(IncomingWebhook $webhook): void
    {
        if ($webhook->summaryId === '404') {
            throw WebhookRequestException::notFound();
        }
    }
}
