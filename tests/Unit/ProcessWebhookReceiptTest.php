<?php

namespace FunnelSphere\CalliopeiaWebhook\Tests\Unit;

use FunnelSphere\CalliopeiaWebhook\Contracts\WebhookHandler;
use FunnelSphere\CalliopeiaWebhook\Jobs\ProcessWebhookReceipt;
use FunnelSphere\CalliopeiaWebhook\Models\WebhookReceipt;
use FunnelSphere\CalliopeiaWebhook\Tests\TestCase;
use RuntimeException;

final class ProcessWebhookReceiptTest extends TestCase
{
    public function test_job_processes_a_receipt_exactly_once(): void
    {
        $receipt = $this->receipt();
        $handler = new RecordingHandler();
        $job = new ProcessWebhookReceipt($receipt->getKey());

        $job->handle($handler);
        $job->handle($handler);

        self::assertSame(1, $handler->calls);
        $receipt->refresh();
        self::assertSame('processed', $receipt->processing_state);
        self::assertNotNull($receipt->processed_at);
    }

    public function test_job_marks_handler_failure_and_can_retry(): void
    {
        $receipt = $this->receipt();
        $job = new ProcessWebhookReceipt($receipt->getKey());

        try {
            $job->handle(new FailingHandler());
            self::fail('Expected handler exception');
        } catch (RuntimeException $exception) {
            self::assertSame('handler failed', $exception->getMessage());
        }

        $receipt->refresh();
        self::assertSame('failed', $receipt->processing_state);
        self::assertSame(RuntimeException::class, $receipt->failure_message);

        $handler = new RecordingHandler();
        $job->handle($handler);
        self::assertSame(1, $handler->calls);
        self::assertSame('processed', $receipt->refresh()->processing_state);
    }

    public function test_job_reclaims_a_stale_processing_lock(): void
    {
        config()->set('calliopeia-webhook.queue.processing_lock_seconds', 30);
        $receipt = $this->receipt([
            'processing_state' => 'processing',
            'processing_started_at' => now()->subMinute(),
        ]);
        $handler = new RecordingHandler();

        (new ProcessWebhookReceipt($receipt->getKey()))->handle($handler);

        self::assertSame(1, $handler->calls);
        self::assertSame('processed', $receipt->refresh()->processing_state);
    }

    /** @param array<string, mixed> $overrides */
    private function receipt(array $overrides = []): WebhookReceipt
    {
        return WebhookReceipt::query()->create(array_merge([
            'deduplication_key' => 'summary:'.fake()->unique()->randomNumber(),
            'event_id' => 'event-'.fake()->uuid(),
            'profile' => 'SUMMARY_CALLBACK_V1',
            'summary_id' => (string) fake()->randomNumber(),
            'payload_sha256' => str_repeat('a', 64),
            'payload' => $this->summaryPayload(),
            'calliopeia_timestamp' => now()->getTimestamp(),
            'delivery_attempt' => 1,
            'processing_state' => 'pending',
            'received_at' => now(),
        ], $overrides));
    }
}

final class RecordingHandler implements WebhookHandler
{
    public int $calls = 0;

    public function handle(WebhookReceipt $receipt): void
    {
        $this->calls++;
    }
}

final class FailingHandler implements WebhookHandler
{
    public function handle(WebhookReceipt $receipt): void
    {
        throw new RuntimeException('handler failed');
    }
}
