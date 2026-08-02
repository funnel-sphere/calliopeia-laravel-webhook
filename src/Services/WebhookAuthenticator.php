<?php

namespace FunnelSphere\CalliopeiaWebhook\Services;

use FunnelSphere\CalliopeiaWebhook\Data\ParsedWebhookRequest;
use FunnelSphere\CalliopeiaWebhook\Exceptions\WebhookRequestException;
use Illuminate\Support\Carbon;
use JsonException;
use OpenSSLAsymmetricKey;

final class WebhookAuthenticator
{
    public function authenticate(ParsedWebhookRequest $request): void
    {
        $this->assertFreshTimestamp($request->timestamp);

        $mode = strtoupper(trim((string) config('calliopeia-webhook.auth.mode', 'BEARER')));
        match ($mode) {
            'BEARER' => $this->authenticateBearer($request),
            'BODY_ACCESS_TOKEN' => $this->authenticateBodyToken($request),
            'RSA_SIGNATURE' => $this->authenticateRsaSignature($request),
            'SIGNED_JWT' => $this->authenticateSignedJwt($request),
            'NONE' => $this->authenticateNone(),
            default => throw WebhookRequestException::misconfigured('Unsupported auth mode: '.$mode),
        };
    }

    private function assertFreshTimestamp(int $timestamp): void
    {
        $tolerance = max(0, (int) config('calliopeia-webhook.timestamp_tolerance_seconds', 600));
        if ($tolerance > 0 && abs(Carbon::now()->getTimestamp() - $timestamp) > $tolerance) {
            throw WebhookRequestException::unauthorized('Webhook timestamp is stale');
        }
    }

    private function authenticateBearer(ParsedWebhookRequest $request): void
    {
        $expected = $this->requiredSecret('calliopeia-webhook.auth.bearer_token');
        $actual = $this->bearerToken($request->authorization);
        if ($actual === null || !hash_equals($expected, $actual)) {
            throw WebhookRequestException::unauthorized();
        }
    }

    private function authenticateBodyToken(ParsedWebhookRequest $request): void
    {
        $expected = $this->requiredSecret('calliopeia-webhook.auth.body_access_token');
        $actual = $request->payload['access_token'] ?? null;
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            throw WebhookRequestException::unauthorized();
        }
    }

    private function authenticateNone(): void
    {
        if (!(bool) config('calliopeia-webhook.auth.allow_none', false)) {
            throw WebhookRequestException::misconfigured('NONE auth requires allow_none=true');
        }
    }

    private function authenticateRsaSignature(ParsedWebhookRequest $request): void
    {
        $expectedKeyId = trim((string) config('calliopeia-webhook.auth.key_id'));
        if ($expectedKeyId !== '' && !hash_equals($expectedKeyId, (string) $request->keyId)) {
            throw WebhookRequestException::unauthorized('Webhook key ID does not match');
        }

        $signature = base64_decode((string) $request->signature, true);
        if ($signature === false || $signature === '') {
            throw WebhookRequestException::unauthorized('Webhook signature is invalid');
        }

        $verified = openssl_verify(
            $request->timestamp.'.'.$request->rawBody,
            $signature,
            $this->publicKey(),
            OPENSSL_ALGO_SHA256,
        );
        if ($verified !== 1) {
            throw WebhookRequestException::unauthorized('Webhook signature verification failed');
        }
    }

    private function authenticateSignedJwt(ParsedWebhookRequest $request): void
    {
        $jwt = $this->bearerToken($request->authorization);
        if ($jwt === null) {
            throw WebhookRequestException::unauthorized();
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw WebhookRequestException::unauthorized('JWT must contain three segments');
        }
        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;

        try {
            $header = $this->decodeJwtObject($encodedHeader);
            $claims = $this->decodeJwtObject($encodedClaims);
        } catch (JsonException) {
            throw WebhookRequestException::unauthorized('JWT contains invalid JSON');
        }

        if (($header['alg'] ?? null) !== 'RS256' || ($header['typ'] ?? null) !== 'JWT') {
            throw WebhookRequestException::unauthorized('JWT algorithm or type is invalid');
        }
        $expectedKeyId = trim((string) config('calliopeia-webhook.auth.key_id'));
        if ($expectedKeyId !== '' && !hash_equals($expectedKeyId, (string) ($header['kid'] ?? ''))) {
            throw WebhookRequestException::unauthorized('JWT key ID does not match');
        }

        $signature = $this->base64UrlDecode($encodedSignature);
        if ($signature === null || openssl_verify(
            $encodedHeader.'.'.$encodedClaims,
            $signature,
            $this->publicKey(),
            OPENSSL_ALGO_SHA256,
        ) !== 1) {
            throw WebhookRequestException::unauthorized('JWT signature verification failed');
        }

        $this->assertJwtClaims($claims, $request);
    }

    /** @param array<string, mixed> $claims */
    private function assertJwtClaims(array $claims, ParsedWebhookRequest $request): void
    {
        $now = Carbon::now()->getTimestamp();
        $skew = max(0, (int) config('calliopeia-webhook.auth.clock_skew_seconds', 30));
        $issuer = (string) config('calliopeia-webhook.auth.issuer', 'calliopeia');
        $audience = trim((string) config('calliopeia-webhook.auth.audience'));
        if ($audience === '') {
            throw WebhookRequestException::misconfigured('SIGNED_JWT requires an audience');
        }

        if (!is_string($claims['iss'] ?? null) || !hash_equals($issuer, $claims['iss'])) {
            throw WebhookRequestException::unauthorized('JWT issuer is invalid');
        }
        if (!$this->audienceContains($claims['aud'] ?? null, $audience)) {
            throw WebhookRequestException::unauthorized('JWT audience is invalid');
        }
        if (!is_int($claims['exp'] ?? null) || $claims['exp'] < $now - $skew) {
            throw WebhookRequestException::unauthorized('JWT has expired');
        }
        if (!is_int($claims['iat'] ?? null) || $claims['iat'] > $now + $skew) {
            throw WebhookRequestException::unauthorized('JWT issued-at claim is invalid');
        }
        if (!is_string($claims['jti'] ?? null) || !hash_equals($request->eventId, $claims['jti'])) {
            throw WebhookRequestException::unauthorized('JWT event ID does not match');
        }
        if (!is_string($claims['sub'] ?? null) || trim($claims['sub']) === '') {
            throw WebhookRequestException::unauthorized('JWT subject is invalid');
        }

        $payloadJobId = $request->payload['job_id'] ?? null;
        if (is_string($payloadJobId) && !hash_equals($payloadJobId, $claims['sub'])) {
            throw WebhookRequestException::unauthorized('JWT subject does not match job_id');
        }
        $payloadSummaryId = $request->payload['summary_id'] ?? null;
        $claimSummaryId = $claims['summary_id'] ?? null;
        if ($claimSummaryId !== null && $payloadSummaryId !== null
            && !hash_equals((string) $payloadSummaryId, (string) $claimSummaryId)) {
            throw WebhookRequestException::unauthorized('JWT summary_id does not match payload');
        }
    }

    private function requiredSecret(string $configKey): string
    {
        $secret = (string) config($configKey);
        if ($secret === '') {
            throw WebhookRequestException::misconfigured($configKey.' is empty');
        }

        return $secret;
    }

    private function bearerToken(?string $authorization): ?string
    {
        if ($authorization === null
            || !preg_match('/\ABearer[\t ]+([^\s]+)\z/iD', trim($authorization), $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function publicKey(): OpenSSLAsymmetricKey
    {
        $path = trim((string) config('calliopeia-webhook.auth.public_key_path'));
        if ($path !== '') {
            if (!is_readable($path)) {
                throw WebhookRequestException::misconfigured('Webhook public key file is not readable');
            }
            $pem = file_get_contents($path);
        } else {
            $pem = str_replace('\\n', "\n", (string) config('calliopeia-webhook.auth.public_key'));
        }

        if (!is_string($pem) || trim($pem) === '') {
            throw WebhookRequestException::misconfigured('Webhook public key is empty');
        }
        $key = openssl_pkey_get_public($pem);
        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw WebhookRequestException::misconfigured('Webhook public key is invalid');
        }

        return $key;
    }

    /** @return array<string, mixed> */
    private function decodeJwtObject(string $value): array
    {
        $decoded = $this->base64UrlDecode($value);
        if ($decoded === null) {
            throw new JsonException('Invalid base64url');
        }
        $object = json_decode($decoded, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($object) || array_is_list($object)) {
            throw new JsonException('JWT segment must contain an object');
        }

        return $object;
    }

    private function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) {
            return null;
        }
        $padded = strtr($value, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    private function audienceContains(mixed $claim, string $expected): bool
    {
        if (is_string($claim)) {
            return hash_equals($expected, $claim);
        }
        if (!is_array($claim) || !array_is_list($claim)) {
            return false;
        }
        foreach ($claim as $audience) {
            if (is_string($audience) && hash_equals($expected, $audience)) {
                return true;
            }
        }

        return false;
    }
}
