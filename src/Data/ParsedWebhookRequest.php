<?php

namespace FunnelSphere\CalliopeiaWebhook\Data;

final readonly class ParsedWebhookRequest
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $rawBody,
        public array $payload,
        public string $eventId,
        public int $timestamp,
        public int $attempt,
        public ?string $authorization,
        public ?string $signature,
        public ?string $keyId,
        public ?string $userAgent,
    ) {
    }
}
