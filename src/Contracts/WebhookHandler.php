<?php

namespace FunnelSphere\CalliopeiaWebhook\Contracts;

use FunnelSphere\CalliopeiaWebhook\Models\WebhookReceipt;

interface WebhookHandler
{
    public function handle(WebhookReceipt $receipt): void;
}
