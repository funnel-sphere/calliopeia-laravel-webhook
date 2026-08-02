<?php

namespace FunnelSphere\CalliopeiaWebhook\Handlers;

use FunnelSphere\CalliopeiaWebhook\Contracts\WebhookHandler;
use FunnelSphere\CalliopeiaWebhook\Events\CalliopeiaWebhookReceived;
use FunnelSphere\CalliopeiaWebhook\Models\WebhookReceipt;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class DispatchWebhookReceivedEvent implements WebhookHandler
{
    public function __construct(private Dispatcher $events)
    {
    }

    public function handle(WebhookReceipt $receipt): void
    {
        $this->events->dispatch(new CalliopeiaWebhookReceived(
            receiptId: $receipt->getKey(),
            profile: $receipt->profile,
            summaryId: $receipt->summary_id,
        ));
    }
}
