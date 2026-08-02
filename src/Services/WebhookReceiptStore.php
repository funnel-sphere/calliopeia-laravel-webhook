<?php

namespace FunnelSphere\CalliopeiaWebhook\Services;

use FunnelSphere\CalliopeiaWebhook\Data\IncomingWebhook;
use FunnelSphere\CalliopeiaWebhook\Exceptions\WebhookRequestException;
use FunnelSphere\CalliopeiaWebhook\Models\WebhookReceipt;

final class WebhookReceiptStore
{
    /** @return array{WebhookReceipt, bool} */
    public function store(IncomingWebhook $webhook): array
    {
        $receipt = WebhookReceipt::query()->firstOrCreate(
            ['deduplication_key' => $webhook->deduplicationKey],
            [
                'event_id' => $webhook->eventId,
                'profile' => $webhook->profile,
                'summary_id' => $webhook->summaryId,
                'payload_sha256' => $webhook->payloadSha256,
                'payload' => $webhook->payload,
                'calliopeia_timestamp' => $webhook->timestamp,
                'delivery_attempt' => $webhook->attempt,
                'user_agent' => $webhook->userAgent,
                'processing_state' => 'pending',
                'received_at' => now(),
            ],
        );

        if (!hash_equals($receipt->payload_sha256, $webhook->payloadSha256)) {
            throw WebhookRequestException::conflict();
        }

        return [$receipt, !$receipt->wasRecentlyCreated];
    }
}
