<?php

namespace FunnelSphere\CalliopeiaWebhook\Events;

final readonly class CalliopeiaWebhookReceived
{
    public function __construct(
        public string $receiptId,
        public string $profile,
        public ?string $summaryId,
    ) {
    }
}
