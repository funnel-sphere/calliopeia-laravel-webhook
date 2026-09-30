<?php

namespace App\Console\Commands;

use FunnelSphere\CalliopeiaWebhook\Models\WebhookReceipt;
use Illuminate\Console\Command;

final class ShowCalliopeiaReceipts extends Command
{
    protected $signature = 'calliopeia:receipts';
    protected $description = 'Show the latest ten persisted webhook receipts without their payloads';

    public function handle(): int
    {
        $this->table(['Receipt ID', 'State', 'Processed at'], WebhookReceipt::latest()->limit(10)->get()
            ->map(fn ($row) => [$row->id, $row->processing_state, $row->processed_at?->toIso8601String()])->all());
        return self::SUCCESS;
    }
}
