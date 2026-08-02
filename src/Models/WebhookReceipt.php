<?php

namespace FunnelSphere\CalliopeiaWebhook\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class WebhookReceipt extends Model
{
    use HasUuids;

    protected $table = 'calliopeia_webhook_receipts';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'calliopeia_timestamp' => 'integer',
            'delivery_attempt' => 'integer',
            'received_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
