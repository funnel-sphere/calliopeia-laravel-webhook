<?php

namespace FunnelSphere\CalliopeiaWebhook\Client;

use FunnelSphere\CalliopeiaWebhook\Exceptions\CalliopeiaApiException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

/** Server-side client. Credentials stay in Laravel, never in an iOS binary. */
final class CalliopeiaClient
{
    public function __construct(private readonly Factory $http, private readonly array $configuration) {}

    public function createAudioUpload(string $fileName, string $contentType = 'audio/mp4'): array
    {
        $this->required($fileName, 'fileName');
        $this->required($contentType, 'contentType');
        $result = $this->graphql(self::UPLOAD, compact('fileName', 'contentType'), 'externalCreateAudioUpload');
        if (!is_array($result['upload'] ?? null)) {
            throw new CalliopeiaApiException('Upload response did not contain a ticket');
        }
        return $result['upload'];
    }

    /** Streams the file to S3. Tenant/AppSync credentials are never sent here. */
    public function uploadAudio(string $path, array $ticket): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('Audio file is not readable');
        }
        $url = $this->https($ticket['uploadUrl'] ?? '', 'uploadUrl');
        if (($ticket['method'] ?? '') !== 'PUT' || !is_string($ticket['contentType'] ?? null)) {
            throw new InvalidArgumentException('Invalid upload ticket');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) { throw new InvalidArgumentException('Cannot open audio file'); }
        try {
            $response = $this->http->withOptions(['allow_redirects' => false])
                ->connectTimeout(15)->timeout($this->configuration['upload_timeout'] ?? 600)
                ->withBody($stream, $ticket['contentType'])->put($url);
            $this->checkHTTP($response);
        } finally {
            if (is_resource($stream)) { fclose($stream); }
        }
    }

    /** Keep the ticket and idempotencyKey if the job request must be retried. */
    public function invokeAudioJob(
        array $ticket, string $fileName, int $fileSizeBytes, string $idempotencyKey,
        ?float $audioSeconds = null, array $options = [],
    ): array {
        $this->required($fileName, 'fileName');
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new InvalidArgumentException('idempotencyKey must have 1...128 characters');
        }
        if ($fileSizeBytes < 1 || $fileSizeBytes > 2147483647) {
            throw new InvalidArgumentException('Audio file must fit the GraphQL signed 32-bit size field');
        }
        if ($audioSeconds !== null && (!is_finite($audioSeconds) || $audioSeconds < 0)) {
            throw new InvalidArgumentException('audioSeconds must be finite and non-negative');
        }
        $allowed = ['processingProfileId', 'bgmSeparation', 'generateIndividualKartes', 'responseMode',
            'webhookEndpointId', 'promptText', 'promptTemplateId', 'promptTitle', 'passthrough',
            'extractionEffort', 'transcriptSupportAuditMode', 'auditEffort', 'auditStrategy', 'auditBatchSize',
            'userId', 'shopId', 'customerId', 'expectedAddressee', 'karteId', 'summaryId'];
        if (array_diff(array_keys($options), $allowed)) {
            throw new InvalidArgumentException('Unknown audio job option');
        }
        if (isset($options['generateIndividualKartes']) && !is_bool($options['generateIndividualKartes'])) {
            throw new InvalidArgumentException('generateIndividualKartes must be boolean');
        }
        $options += ['processingProfileId' => 'quality_batch', 'responseMode' => 'ASYNC'];
        if ($options['processingProfileId'] === 'quality_batch') {
            $options += ['generateIndividualKartes' => false, 'bgmSeparation' => 'off',
                'extractionEffort' => 'MAX', 'transcriptSupportAuditMode' => 'OFF'];
        }
        if (isset($options['bgmSeparation']) && !in_array($options['bgmSeparation'], ['on', 'off'], true)) {
            throw new InvalidArgumentException('bgmSeparation must be on or off');
        }
        if (!in_array($options['responseMode'], ['ASYNC', 'SUBSCRIPTION', 'WEBHOOK'], true)) {
            throw new InvalidArgumentException('Invalid responseMode');
        }
        if ($options['responseMode'] === 'WEBHOOK' && empty($options['webhookEndpointId'])) {
            throw new InvalidArgumentException('webhookEndpointId is required');
        }
        if (isset($options['passthrough'])) {
            if (!is_array($options['passthrough']) && !is_object($options['passthrough'])) {
                throw new InvalidArgumentException('passthrough must be a JSON object');
            }
            $object = (object) $options['passthrough'];
            $json = json_encode($object, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (strlen($json) > 16384) { throw new InvalidArgumentException('passthrough exceeds 16 KiB'); }
            $options['passthrough'] = $json;
        }
        $this->required($ticket['objectKey'] ?? '', 'objectKey');
        return $this->graphql(self::INVOKE, $options + [
            'objectKey' => $ticket['objectKey'], 'contentType' => $ticket['contentType'] ?? 'audio/mp4',
            'fileName' => $fileName, 'fileSizeBytes' => $fileSizeBytes,
            'audioSeconds' => $audioSeconds, 'idempotencyKey' => $idempotencyKey,
        ], 'externalInvokeAudioJob');
    }

    public function submitAudio(string $path, string $idempotencyKey, ?float $audioSeconds = null, array $options = [], string $contentType = 'audio/mp4'): array
    {
        if (!is_file($path) || !is_readable($path) || filesize($path) < 1) {
            throw new InvalidArgumentException('Audio file must be readable and non-empty');
        }
        $ticket = $this->createAudioUpload(basename($path), $contentType);
        $this->uploadAudio($path, $ticket);
        return $this->invokeAudioJob($ticket, basename($path), filesize($path), $idempotencyKey, $audioSeconds, $options);
    }

    public function getJob(string $jobId): array { return $this->get($jobId); }
    public function getVisits(string $jobId): array { return $this->get($jobId, '/visits'); }
    public function getProvisionalTranscript(string $jobId): array { return $this->get($jobId, '/transcripts/provisional'); }
    public function getFormattedTranscript(string $jobId): array { return $this->get($jobId, '/transcripts/formatted'); }

    /** Call only after displaying the returned quote and obtaining price consent. */
    public function purchaseFormattedTranscript(string $jobId, string $quoteToken, bool $acceptCharge): array
    {
        if (!$acceptCharge) { throw new InvalidArgumentException('Explicit charge consent is required'); }
        $this->required($quoteToken, 'quoteToken');
        return $this->rest('POST', $jobId, '/transcripts/formatted', compact('quoteToken', 'acceptCharge'));
    }

    public function getQuestions(string $jobId, ?string $nextToken = null): array
    {
        return $this->rest('GET', $jobId, '/questions', array_filter(['nextToken' => $nextToken], fn ($v) => $v !== null));
    }

    public function getQuestion(string $jobId, string $questionId): array
    {
        $this->required($questionId, 'questionId');
        return $this->get($jobId, '/questions/'.rawurlencode($questionId));
    }

    public function askQuestion(string $jobId, string $question, string $requestId, ?string $parentQuestionId = null, ?int $sectionIndex = null): array
    {
        if (trim($question) === '' || mb_strlen($question) > 4000 || strlen($requestId) < 8 || strlen($requestId) > 128 || ($sectionIndex !== null && $sectionIndex < 0)) {
            throw new InvalidArgumentException('Invalid question, requestId or sectionIndex');
        }
        return $this->rest('POST', $jobId, '/questions', array_filter(compact('question', 'requestId', 'parentQuestionId', 'sectionIndex'), fn ($v) => $v !== null));
    }

    private function get(string $jobId, string $suffix = ''): array { return $this->rest('GET', $jobId, $suffix); }

    private function rest(string $method, string $jobId, string $suffix, array $parameters = []): array
    {
        $this->required($jobId, 'jobId');
        $url = rtrim($this->https($this->configuration['pull_url'] ?? '', 'pull_url'), '/')
            .'/v1/jobs/'.rawurlencode($jobId).$suffix;
        $request = $this->http->acceptJson()->withToken($this->apiKey())
            ->withOptions(['allow_redirects' => false])->connectTimeout(15)->timeout($this->configuration['timeout'] ?? 60);
        $response = $method === 'GET' ? $request->get($url, $parameters) : $request->post($url, $parameters);
        $this->checkHTTP($response);
        $payload = $response->json();
        if (!is_array($payload) || ($payload['success'] ?? false) !== true) {
            throw new CalliopeiaApiException('Calliopeia rejected the API operation', $response->status(), $response->header('X-Request-Id'));
        }
        // HTTP 202 is a pending result; callers inspect state/status before using it.
        return $payload;
    }

    private function graphql(string $query, array $variables, string $field): array
    {
        $url = $this->https($this->configuration['graphql_url'] ?? '', 'graphql_url');
        $appSyncKey = $this->configuration['appsync_api_key'] ?? '';
        $this->required($appSyncKey, 'appsync_api_key');
        $variables += ['credential' => $this->apiKey(), 'authType' => 'API_KEY'];
        $response = $this->http->acceptJson()->withHeaders(['x-api-key' => $appSyncKey])
            ->withOptions(['allow_redirects' => false])->connectTimeout(15)->timeout($this->configuration['timeout'] ?? 60)
            ->post($url, compact('query', 'variables'));
        $this->checkHTTP($response);
        $payload = $response->json();
        if (!is_array($payload) || !empty($payload['errors']) || ($payload['data'][$field]['success'] ?? false) !== true) {
            throw new CalliopeiaApiException('Calliopeia rejected the GraphQL operation', $response->status(), $response->header('X-Request-Id'));
        }
        return $payload['data'][$field];
    }

    private function apiKey(): string
    {
        $key = $this->configuration['api_key'] ?? '';
        $this->required($key, 'api_key');
        return $key;
    }

    private function https(string $url, string $name): string
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException("$name must be an HTTPS URL without credentials or a fragment");
        }
        return $url;
    }

    private function required(string $value, string $name): void
    {
        if (trim($value) === '') { throw new InvalidArgumentException("$name is required"); }
    }

    private function checkHTTP(Response $response): void
    {
        if (!$response->successful()) {
            $retry = $response->header('Retry-After');
            throw new CalliopeiaApiException('Calliopeia HTTP request failed', $response->status(),
                $response->header('X-Request-Id'), ctype_digit($retry) ? (int) $retry : null);
        }
    }

    private const UPLOAD = <<<'GRAPHQL'
    mutation Upload($credential: String!, $authType: String!, $fileName: String!, $contentType: String!) {
      externalCreateAudioUpload(credential: $credential, authType: $authType, fileName: $fileName, contentType: $contentType) {
        success error upload { objectKey uploadUrl method contentType expiresAt }
      }
    }
    GRAPHQL;

    private const INVOKE = <<<'GRAPHQL'
    mutation Invoke($credential: String!, $authType: String!, $objectKey: String!, $fileName: String!,
      $contentType: String, $fileSizeBytes: Int, $audioSeconds: Float, $processingProfileId: String,
      $bgmSeparation: String, $generateIndividualKartes: Boolean, $responseMode: String,
      $webhookEndpointId: String, $idempotencyKey: String, $promptText: String, $promptTemplateId: String,
      $promptTitle: String, $passthrough: AWSJSON, $extractionEffort: String, $transcriptSupportAuditMode: String,
      $auditEffort: String, $auditStrategy: String, $auditBatchSize: Int, $userId: String, $shopId: String,
      $customerId: String, $expectedAddressee: String, $karteId: String, $summaryId: String) {
      externalInvokeAudioJob(credential: $credential, authType: $authType, objectKey: $objectKey,
        fileName: $fileName, contentType: $contentType, fileSizeBytes: $fileSizeBytes, audioSeconds: $audioSeconds,
        processingProfileId: $processingProfileId, bgmSeparation: $bgmSeparation,
        generateIndividualKartes: $generateIndividualKartes, responseMode: $responseMode,
        webhookEndpointId: $webhookEndpointId, idempotencyKey: $idempotencyKey, promptText: $promptText,
        promptTemplateId: $promptTemplateId, promptTitle: $promptTitle, passthrough: $passthrough,
        extractionEffort: $extractionEffort, transcriptSupportAuditMode: $transcriptSupportAuditMode,
        auditEffort: $auditEffort, auditStrategy: $auditStrategy, auditBatchSize: $auditBatchSize,
        userId: $userId, shopId: $shopId, customerId: $customerId, expectedAddressee: $expectedAddressee,
        karteId: $karteId, summaryId: $summaryId) {
        success error message idempotentReplay statusUrl subscriptionToken
        job { id status externalSummaryId externalIdempotencyKey }
      }
    }
    GRAPHQL;
}
