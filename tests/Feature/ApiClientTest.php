<?php

namespace FunnelSphere\CalliopeiaWebhook\Tests\Feature;

use FunnelSphere\CalliopeiaWebhook\Client\CalliopeiaClient;
use FunnelSphere\CalliopeiaWebhook\Exceptions\CalliopeiaApiException;
use FunnelSphere\CalliopeiaWebhook\Tests\TestCase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use InvalidArgumentException;

final class ApiClientTest extends TestCase
{
    private function client(Factory $http): CalliopeiaClient
    {
        return new CalliopeiaClient($http, [
            'graphql_url' => 'https://graphql.example.test/graphql', 'appsync_api_key' => 'app-key',
            'api_key' => 'tenant-key', 'pull_url' => 'https://pull.example.test',
        ]);
    }

    public function test_default_off_and_idempotency_are_sent_to_api(): void
    {
        $http = new Factory;
        $http->fake(fn () => Factory::response(['data' => ['externalInvokeAudioJob' => ['success' => true, 'job' => ['id' => 'saved']]]]));
        $result = $this->client($http)->invokeAudioJob(['objectKey' => 'key', 'contentType' => 'audio/mp4'], 'take.m4a', 1024, 'stable-id', 60);
        self::assertSame('saved', $result['job']['id']);
        $http->assertSent(fn (Request $r) => $r['variables']['generateIndividualKartes'] === false
            && $r['variables']['idempotencyKey'] === 'stable-id'
            && $r['variables']['bgmSeparation'] === 'off');
    }

    public function test_question_zero_section_and_parent_survive_and_pending_is_not_completion(): void
    {
        $http = new Factory;
        $http->fake(fn () => Factory::response(['success' => true, 'question' => ['status' => 'PROCESSING']], 202));
        $result = $this->client($http)->askQuestion('job', 'Question?', 'stable-request', 'parent', 0);
        self::assertSame('PROCESSING', $result['question']['status']);
        $http->assertSent(fn (Request $r) => $r['sectionIndex'] === 0 && $r['parentQuestionId'] === 'parent' && !isset($r['generateIndividualKartes']));
    }

    public function test_upload_does_not_receive_api_credentials(): void
    {
        $http = new Factory;
        $http->fake(function (Request $request) {
            self::assertFalse($request->hasHeader('Authorization'));
            self::assertFalse($request->hasHeader('x-api-key'));
            self::assertSame('audio-bytes', $request->body());
            return Factory::response('', 200);
        });
        $path = tempnam(sys_get_temp_dir(), 'calliopeia-');
        file_put_contents($path, 'audio-bytes');
        try {
            $this->client($http)->uploadAudio($path, ['uploadUrl' => 'https://s3.example.test/audio', 'method' => 'PUT', 'contentType' => 'audio/mp4']);
            $http->assertSent(fn (Request $r) => !$r->hasHeader('Authorization') && !$r->hasHeader('x-api-key'));
        } finally { unlink($path); }
    }

    public function test_charge_consent_is_not_inferred(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $this->expectException(InvalidArgumentException::class);
        $this->client($http)->purchaseFormattedTranscript('job', 'quote', false);
    }

    public function test_failed_response_preserves_retry_metadata_without_echoing_body(): void
    {
        $http = new Factory;
        $http->fake(fn () => Factory::response('private-body', 429, ['Retry-After' => '12', 'X-Request-Id' => 'request-42']));
        try {
            $this->client($http)->getJob('job');
            self::fail('must throw');
        } catch (CalliopeiaApiException $e) {
            self::assertSame(12, $e->retryAfterSeconds);
            self::assertSame('request-42', $e->requestId);
            self::assertStringNotContainsString('private-body', $e->getMessage());
        }
    }
}
