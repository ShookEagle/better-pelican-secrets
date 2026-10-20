<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bps_application_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('node_id');
            $table->uuid('attempt_id');
            $table->string('revision', 20);
            $table->string('kind', 16);
            $table->char('payload_digest', 64);
            $table->json('payload');
            $table->timestamp('created_at');
            $table->unique(['server_id', 'attempt_id', 'kind', 'payload_digest'], 'bps_application_report_idempotency');
            $table->index(['server_id', 'attempt_id']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bps_application_records');
    }
};
