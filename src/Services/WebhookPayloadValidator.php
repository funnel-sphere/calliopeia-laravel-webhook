<?php

namespace FunnelSphere\CalliopeiaWebhook\Services;

use DateTimeImmutable;
use FunnelSphere\CalliopeiaWebhook\Data\IncomingWebhook;
use FunnelSphere\CalliopeiaWebhook\Data\ParsedWebhookRequest;
use FunnelSphere\CalliopeiaWebhook\Exceptions\WebhookRequestException;
use JsonException;
use Throwable;

final class WebhookPayloadValidator
{
    private const SUMMARY_PROFILE = 'SUMMARY_CALLBACK_V1';
    private const EVENT_PROFILE = 'EVENT_ENVELOPE_V1';

    public function validate(ParsedWebhookRequest $request): IncomingWebhook
    {
        $profile = $this->detectProfile($request->payload);
        $expected = strtoupper(trim((string) config('calliopeia-webhook.payload_profile', 'AUTO')));
        if (!in_array($expected, ['AUTO', self::SUMMARY_PROFILE, self::EVENT_PROFILE], true)) {
            throw WebhookRequestException::misconfigured('Unsupported payload profile: '.$expected);
        }
        if ($expected !== 'AUTO' && $expected !== $profile) {
            throw WebhookRequestException::invalid('Payload profile does not match receiver configuration');
        }

        [$sanitized, $summaryId] = $profile === self::SUMMARY_PROFILE
            ? $this->validateSummary($request->payload)
            : $this->validateEvent($request->payload, $request->eventId);

        $deduplicationKey = $profile === self::SUMMARY_PROFILE
            ? 'summary:'.$summaryId
            : 'event:'.$request->eventId;

        return new IncomingWebhook(
            profile: $profile,
            eventId: $request->eventId,
            deduplicationKey: $deduplicationKey,
            summaryId: $summaryId,
            payload: $sanitized,
            payloadSha256: hash('sha256', $this->canonicalJson($sanitized)),
            timestamp: $request->timestamp,
            attempt: $request->attempt,
            userAgent: $request->userAgent,
        );
    }

    /** @param array<string, mixed> $payload */
    private function detectProfile(array $payload): string
    {
        if (array_key_exists('version', $payload) || array_key_exists('event_id', $payload)) {
            return self::EVENT_PROFILE;
        }
        if (array_key_exists('summary_id', $payload) && array_key_exists('status', $payload)) {
            return self::SUMMARY_PROFILE;
        }

        throw WebhookRequestException::invalid('Payload profile could not be detected');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{array<string, mixed>, string}
     */
    private function validateSummary(array $payload): array
    {
        $this->assertAllowedKeys($payload, [
            'summary_id', 'status', 'summarized_json', 'error_message', 'transcribed_text',
        ]);
        if (!is_int($payload['summary_id'] ?? null)) {
            throw WebhookRequestException::invalid('summary_id must be a JSON integer');
        }
        $status = $payload['status'] ?? null;
        if (!is_string($status) || !in_array($status, ['succeeded', 'failed'], true)) {
            throw WebhookRequestException::invalid('status must be succeeded or failed');
        }
        if (array_key_exists('transcribed_text', $payload)
            && !is_string($payload['transcribed_text'])) {
            throw WebhookRequestException::invalid('transcribed_text must be a string');
        }

        if ($status === 'succeeded') {
            $sections = $payload['summarized_json'] ?? null;
            if (!is_array($sections) || !array_is_list($sections) || $sections === []) {
                throw WebhookRequestException::invalid('summarized_json must be a non-empty array');
            }
            foreach ($sections as $index => $section) {
                if (!is_array($section) || array_is_list($section)) {
                    throw WebhookRequestException::invalid("summarized_json.$index must be an object");
                }
                $this->assertAllowedKeys($section, ['title', 'body']);
                foreach (['title', 'body'] as $field) {
                    $value = $section[$field] ?? null;
                    if (!is_string($value) || trim($value) === '' || strpbrk($value, '<>') !== false) {
                        throw WebhookRequestException::invalid("summarized_json.$index.$field is invalid");
                    }
                }
                if (mb_strlen($section['body']) > 30_000) {
                    throw WebhookRequestException::invalid("summarized_json.$index.body exceeds 30000 characters");
                }
            }
            if (array_key_exists('error_message', $payload) && $payload['error_message'] !== null) {
                throw WebhookRequestException::invalid('error_message must be null for succeeded payloads');
            }
        } else {
            if (array_key_exists('summarized_json', $payload) && $payload['summarized_json'] !== null) {
                throw WebhookRequestException::invalid('summarized_json must be omitted for failed payloads');
            }
            if (!is_string($payload['error_message'] ?? null)
                || trim((string) $payload['error_message']) === '') {
                throw WebhookRequestException::invalid('error_message is required for failed payloads');
            }
        }

        return [$payload, (string) $payload['summary_id']];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{array<string, mixed>, string}
     */
    private function validateEvent(array $payload, string $headerEventId): array
    {
        $this->assertAllowedKeys($payload, [
            'version', 'event_id', 'event_type', 'occurred_at', 'job_id', 'summary_id',
            'idempotency_key', 'user_id', 'shop_id', 'customer_id', 'karte_id',
            'access_token', 'status', 'processing', 'result', 'error', 'review',
        ]);

        if (($payload['version'] ?? null) !== '2026-07-25') {
            throw WebhookRequestException::invalid('Unsupported event envelope version');
        }
        if (!is_string($payload['event_id'] ?? null)
            || !hash_equals($headerEventId, $payload['event_id'])) {
            throw WebhookRequestException::invalid('event_id must match X-Calliopeia-Event-Id');
        }
        $status = $payload['status'] ?? null;
        $eventTypes = [
            'COMPLETED' => 'audio.summary.completed',
            'REVIEW_REQUIRED' => 'audio.summary.review_required',
            'AUDIT_INCOMPLETE' => 'audio.summary.audit_incomplete',
            'ERROR' => 'audio.summary.failed',
        ];
        if (!is_string($status) || !isset($eventTypes[$status])) {
            throw WebhookRequestException::invalid('Event status is invalid');
        }
        if (($payload['event_type'] ?? null) !== $eventTypes[$status]) {
            throw WebhookRequestException::invalid('event_type does not match status');
        }

        $occurredAt = $payload['occurred_at'] ?? null;
        if (!is_string($occurredAt) || trim($occurredAt) === '') {
            throw WebhookRequestException::invalid('occurred_at is required');
        }
        try {
            new DateTimeImmutable($occurredAt);
        } catch (Throwable) {
            throw WebhookRequestException::invalid('occurred_at is not a valid date-time');
        }

        $this->assertNonEmptyString($payload, 'job_id');
        $summaryId = $payload['summary_id'] ?? null;
        if ((!is_string($summaryId) && !is_int($summaryId)) || trim((string) $summaryId) === '') {
            throw WebhookRequestException::invalid('summary_id must be a string or integer');
        }
        foreach (['idempotency_key', 'user_id', 'shop_id', 'customer_id', 'karte_id'] as $field) {
            $this->assertNullableString($payload, $field);
        }

        $this->validateProcessing($payload['processing'] ?? null);
        $this->validateTerminalData($payload, $status);

        $mode = strtoupper(trim((string) config('calliopeia-webhook.auth.mode', 'BEARER')));
        if ($mode === 'BODY_ACCESS_TOKEN') {
            if (!is_string($payload['access_token'] ?? null) || $payload['access_token'] === '') {
                throw WebhookRequestException::invalid('access_token is required for BODY_ACCESS_TOKEN');
            }
        } elseif (array_key_exists('access_token', $payload)) {
            throw WebhookRequestException::invalid('access_token is not allowed for the configured auth mode');
        }

        $sanitized = $payload;
        unset($sanitized['access_token']);

        return [$sanitized, (string) $summaryId];
    }

    private function validateProcessing(mixed $processing): void
    {
        if (!is_array($processing) || array_is_list($processing)) {
            throw WebhookRequestException::invalid('processing must be an object');
        }
        $this->assertAllowedKeys($processing, [
            'extraction_level', 'extraction_effort', 'audit_mode', 'audit_effort',
            'audit_strategy', 'audit_batch_size',
        ]);
        foreach (['extraction_level', 'extraction_effort', 'audit_mode', 'audit_effort', 'audit_strategy'] as $field) {
            $this->assertNullableString($processing, $field);
        }
        if (array_key_exists('audit_batch_size', $processing)
            && $processing['audit_batch_size'] !== null
            && !is_int($processing['audit_batch_size'])) {
            throw WebhookRequestException::invalid('processing.audit_batch_size must be an integer or null');
        }
    }

    /** @param array<string, mixed> $payload */
    private function validateTerminalData(array $payload, string $status): void
    {
        foreach (['result', 'error', 'review'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw WebhookRequestException::invalid($field.' is required in the event envelope');
            }
        }

        if ($status === 'COMPLETED') {
            $result = $payload['result'];
            if (!is_array($result) || array_is_list($result)) {
                throw WebhookRequestException::invalid('result must be an object for COMPLETED');
            }
            $this->assertAllowedKeys($result, ['text', 'data']);
            if (!array_key_exists('text', $result)
                || !array_key_exists('data', $result)
                || (!is_string($result['text'] ?? null) && ($result['text'] ?? null) !== null)) {
                throw WebhookRequestException::invalid('result must contain nullable text and data');
            }
            if ($payload['error'] !== null || $payload['review'] !== null) {
                throw WebhookRequestException::invalid('error and review must be null for COMPLETED');
            }
            return;
        }

        if ($payload['result'] !== null) {
            throw WebhookRequestException::invalid('result must be null unless status is COMPLETED');
        }
        if ($status === 'ERROR') {
            $error = $payload['error'];
            if (!is_array($error) || array_is_list($error)) {
                throw WebhookRequestException::invalid('error must be an object for ERROR');
            }
            $this->assertAllowedKeys($error, ['message']);
            $this->assertNonEmptyString($error, 'message');
            if ($payload['review'] !== null) {
                throw WebhookRequestException::invalid('review must be null for ERROR');
            }
            return;
        }

        $review = $payload['review'];
        if (!is_array($review) || array_is_list($review)) {
            throw WebhookRequestException::invalid('review must be an object for review-blocked statuses');
        }
        $this->assertAllowedKeys($review, ['state', 'message']);
        $this->assertNonEmptyString($review, 'state');
        $this->assertNonEmptyString($review, 'message');
        if ($payload['error'] !== null) {
            throw WebhookRequestException::invalid('error must be null for review-blocked statuses');
        }
    }

    /** @param array<string, mixed> $value */
    private function assertAllowedKeys(array $value, array $allowed): void
    {
        $unknown = array_diff(array_keys($value), $allowed);
        if ($unknown !== []) {
            throw WebhookRequestException::invalid('Unknown field: '.implode(', ', $unknown));
        }
    }

    /** @param array<string, mixed> $value */
    private function assertNonEmptyString(array $value, string $field): void
    {
        if (!is_string($value[$field] ?? null) || trim((string) $value[$field]) === '') {
            throw WebhookRequestException::invalid($field.' must be a non-empty string');
        }
    }

    /** @param array<string, mixed> $value */
    private function assertNullableString(array $value, string $field): void
    {
        if (!array_key_exists($field, $value)) {
            throw WebhookRequestException::invalid($field.' is required');
        }
        if ($value[$field] !== null && !is_string($value[$field])) {
            throw WebhookRequestException::invalid($field.' must be a string or null');
        }
    }

    /** @param array<string, mixed> $payload */
    private function canonicalJson(array $payload): string
    {
        try {
            return json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw WebhookRequestException::invalid('Payload cannot be canonicalized: '.$exception->getMessage());
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
