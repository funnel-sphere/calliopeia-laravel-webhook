<?php

namespace FunnelSphere\CalliopeiaWebhook\Services;

use FunnelSphere\CalliopeiaWebhook\Data\ParsedWebhookRequest;
use FunnelSphere\CalliopeiaWebhook\Exceptions\WebhookRequestException;
use Illuminate\Http\Request;
use JsonException;

final class WebhookRequestParser
{
    public function parse(Request $request): ParsedWebhookRequest
    {
        $contentType = strtolower(trim(explode(';', (string) $request->header('content-type'))[0]));
        if ($contentType !== 'application/json' && !str_ends_with($contentType, '+json')) {
            throw WebhookRequestException::unsupportedMediaType();
        }

        $rawBody = $request->getContent();
        $maxBytes = max(1, (int) config('calliopeia-webhook.max_body_bytes', 184_320));
        if (strlen($rawBody) > $maxBytes) {
            throw WebhookRequestException::tooLarge();
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw WebhookRequestException::invalid('Malformed JSON: '.$exception->getMessage());
        }

        if (!is_array($payload) || !str_starts_with(ltrim($rawBody), '{')) {
            throw WebhookRequestException::invalid('Webhook body must be a JSON object');
        }

        $eventId = trim((string) $request->header('x-calliopeia-event-id'));
        if (!preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $eventId)) {
            throw WebhookRequestException::invalid('X-Calliopeia-Event-Id is missing or invalid');
        }

        $timestampValue = trim((string) $request->header('x-calliopeia-timestamp'));
        if (!preg_match('/\A\d{1,20}\z/D', $timestampValue)) {
            throw WebhookRequestException::invalid('X-Calliopeia-Timestamp is missing or invalid');
        }
        $timestamp = filter_var($timestampValue, FILTER_VALIDATE_INT);
        if (!is_int($timestamp) || $timestamp < 0) {
            throw WebhookRequestException::invalid('X-Calliopeia-Timestamp is outside the supported range');
        }

        $attemptValue = trim((string) $request->header('x-calliopeia-attempt'));
        if (!preg_match('/\A\d{1,3}\z/D', $attemptValue)) {
            throw WebhookRequestException::invalid('X-Calliopeia-Attempt is missing or invalid');
        }
        $attempt = (int) $attemptValue;
        if ($attempt < 1) {
            throw WebhookRequestException::invalid('X-Calliopeia-Attempt must be at least 1');
        }

        $userAgent = trim((string) $request->header('user-agent'));

        return new ParsedWebhookRequest(
            rawBody: $rawBody,
            payload: $payload,
            eventId: $eventId,
            timestamp: $timestamp,
            attempt: $attempt,
            authorization: $request->header('authorization'),
            signature: $request->header('x-calliopeia-signature'),
            keyId: $request->header('x-calliopeia-key-id'),
            userAgent: $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
        );
    }
}
