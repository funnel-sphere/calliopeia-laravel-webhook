<?php

namespace FunnelSphere\CalliopeiaWebhook\Policies;

use FunnelSphere\CalliopeiaWebhook\Contracts\WebhookAcceptancePolicy;
use FunnelSphere\CalliopeiaWebhook\Data\IncomingWebhook;

final class AcceptAllWebhooks implements WebhookAcceptancePolicy
{
    public function assertAcceptable(IncomingWebhook $webhook): void
    {
    }
}
