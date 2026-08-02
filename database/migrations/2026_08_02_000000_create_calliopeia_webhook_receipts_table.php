<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calliopeia_webhook_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('deduplication_key', 191)->unique();
            $table->string('event_id', 128)->index();
            $table->string('profile', 32);
            $table->string('summary_id', 191)->nullable()->index();
            $table->string('payload_sha256', 64);
            $table->longText('payload');
            $table->unsignedBigInteger('calliopeia_timestamp');
            $table->unsignedSmallInteger('delivery_attempt');
            $table->string('user_agent', 255)->nullable();
            $table->string('processing_state', 24)->default('pending')->index();
            $table->timestampTz('received_at');
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calliopeia_webhook_receipts');
    }
};
