<?php

namespace FunnelSphere\CalliopeiaWebhook\Http\Controllers;

use FunnelSphere\CalliopeiaWebhook\Contracts\WebhookAcceptancePolicy;
use FunnelSphere\CalliopeiaWebhook\Exceptions\WebhookRequestException;
use FunnelSphere\CalliopeiaWebhook\Jobs\ProcessWebhookReceipt;
use FunnelSphere\CalliopeiaWebhook\Services\WebhookAuthenticator;
use FunnelSphere\CalliopeiaWebhook\Services\WebhookPayloadValidator;
use FunnelSphere\CalliopeiaWebhook\Services\WebhookReceiptStore;
use FunnelSphere\CalliopeiaWebhook\Services\WebhookRequestParser;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class WebhookController
{
    public function __construct(
        private WebhookRequestParser $parser,
        private WebhookAuthenticator $authenticator,
        private WebhookPayloadValidator $validator,
        private WebhookAcceptancePolicy $acceptancePolicy,
        private WebhookReceiptStore $receipts,
        private Dispatcher $bus,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $parsed = $this->parser->parse($request);
            $this->authenticator->authenticate($parsed);
            $webhook = $this->validator->validate($parsed);
            $this->acceptancePolicy->assertAcceptable($webhook);
            [$receipt, $duplicate] = $this->receipts->store($webhook);
        } catch (WebhookRequestException $exception) {
            return new JsonResponse([
                'accepted' => false,
                'error' => $exception->errorCode,
            ], $exception->status);
        }

        if ($receipt->queued_at === null && $receipt->processing_state !== 'processed') {
            $job = new ProcessWebhookReceipt($receipt->getKey());
            $connection = trim((string) config('calliopeia-webhook.queue.connection'));
            $queue = trim((string) config('calliopeia-webhook.queue.name', 'calliopeia-webhooks'));
            if ($connection !== '') {
                $job->onConnection($connection);
            }
            if ($queue !== '') {
                $job->onQueue($queue);
            }
            $this->bus->dispatch($job);
            $receipt->forceFill(['queued_at' => now()])->save();
        }

        return new JsonResponse([
            'accepted' => true,
            'duplicate' => $duplicate,
            'event_id' => $webhook->eventId,
        ], 200);
    }
}
