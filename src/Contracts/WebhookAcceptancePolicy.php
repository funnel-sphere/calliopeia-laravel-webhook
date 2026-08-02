<?php

namespace FunnelSphere\CalliopeiaWebhook\Contracts;

use FunnelSphere\CalliopeiaWebhook\Data\IncomingWebhook;

interface WebhookAcceptancePolicy
{
    public function assertAcceptable(IncomingWebhook $webhook): void;
}
