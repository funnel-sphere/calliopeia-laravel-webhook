<?php

namespace FunnelSphere\CalliopeiaWebhook\Data;

final readonly class IncomingWebhook
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $profile,
        public string $eventId,
        public string $deduplicationKey,
        public ?string $summaryId,
        public array $payload,
        public string $payloadSha256,
        public int $timestamp,
        public int $attempt,
        public ?string $userAgent,
    ) {
    }
}
