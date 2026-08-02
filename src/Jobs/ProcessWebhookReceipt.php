<?php

namespace FunnelSphere\CalliopeiaWebhook\Jobs;

use FunnelSphere\CalliopeiaWebhook\Contracts\WebhookHandler;
use FunnelSphere\CalliopeiaWebhook\Models\WebhookReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ProcessWebhookReceipt implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $maxExceptions = 5;

    public int $timeout = 30;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $receiptId)
    {
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    public function uniqueId(): string
    {
        return $this->receiptId;
    }

    public function handle(WebhookHandler $handler): void
    {
        $receipt = WebhookReceipt::query()->find($this->receiptId);
        if ($receipt === null || $receipt->processing_state === 'processed') {
            return;
        }

        $lockSeconds = max(30, (int) config('calliopeia-webhook.queue.processing_lock_seconds', 300));
        if ($receipt->processing_state === 'processing'
            && $receipt->processing_started_at !== null
            && $receipt->processing_started_at->isAfter(now()->subSeconds($lockSeconds))) {
            if ($this->job !== null) {
                $this->release(min(30, $lockSeconds));
            }
            return;
        }

        $claimed = WebhookReceipt::query()
            ->whereKey($this->receiptId)
            ->where('processing_state', '!=', 'processed')
            ->where(function ($query) use ($lockSeconds): void {
                $query->where('processing_state', '!=', 'processing')
                    ->orWhereNull('processing_started_at')
                    ->orWhere('processing_started_at', '<=', now()->subSeconds($lockSeconds));
            })
            ->update([
                'processing_state' => 'processing',
                'processing_started_at' => now(),
                'failure_message' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            if ($this->job !== null) {
                $this->release(min(30, $lockSeconds));
            }
            return;
        }

        $receipt = WebhookReceipt::query()->findOrFail($this->receiptId);
        try {
            $handler->handle($receipt);
            WebhookReceipt::query()->whereKey($this->receiptId)->update([
                'processing_state' => 'processed',
                'processed_at' => now(),
                'failure_message' => null,
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            WebhookReceipt::query()->whereKey($this->receiptId)->update([
                'processing_state' => 'failed',
                'failure_message' => $exception::class,
                'updated_at' => now(),
            ]);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        WebhookReceipt::query()->whereKey($this->receiptId)->update([
            'processing_state' => 'failed',
            'queued_at' => null,
            'failure_message' => $exception?->getMessage() !== null ? $exception::class : 'unknown',
            'updated_at' => now(),
        ]);
    }
}
