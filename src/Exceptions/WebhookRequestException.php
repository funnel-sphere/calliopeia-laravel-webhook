<?php

namespace FunnelSphere\CalliopeiaWebhook\Exceptions;

use RuntimeException;

final class WebhookRequestException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $internalMessage = '',
    ) {
        parent::__construct($internalMessage !== '' ? $internalMessage : $errorCode);
    }

    public static function unauthorized(string $message = 'Webhook authentication failed'): self
    {
        return new self(401, 'unauthorized', $message);
    }

    public static function notFound(string $message = 'Webhook target was not found'): self
    {
        return new self(404, 'unknown_summary', $message);
    }

    public static function invalid(string $message = 'Webhook contract validation failed'): self
    {
        return new self(422, 'invalid_webhook', $message);
    }

    public static function unsupportedMediaType(string $message = 'Content-Type must be application/json'): self
    {
        return new self(415, 'unsupported_media_type', $message);
    }

    public static function tooLarge(string $message = 'Webhook body is too large'): self
    {
        return new self(413, 'payload_too_large', $message);
    }

    public static function conflict(string $message = 'Idempotency key was reused with different content'): self
    {
        return new self(422, 'idempotency_conflict', $message);
    }

    public static function misconfigured(string $message = 'Webhook receiver is not configured'): self
    {
        return new self(500, 'receiver_misconfigured', $message);
    }
}
