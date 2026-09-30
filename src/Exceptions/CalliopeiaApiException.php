<?php

namespace FunnelSphere\CalliopeiaWebhook\Exceptions;

use RuntimeException;

final class CalliopeiaApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $requestId = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
