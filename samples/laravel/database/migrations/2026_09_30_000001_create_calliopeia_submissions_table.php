<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('calliopeia_submissions', function (Blueprint $table) {
            $table->string('id', 128)->primary();
            $table->text('request');
            $table->text('ticket')->nullable();
            $table->string('job_id')->nullable();
            $table->longText('result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calliopeia_submissions');
    }
};
