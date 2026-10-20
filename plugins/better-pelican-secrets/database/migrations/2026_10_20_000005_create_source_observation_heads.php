<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bps_source_heads', function (Blueprint $table) {
            $table->unsignedInteger('server_id');
            $table->uuid('attempt_id');
            $table->unsignedBigInteger('application_record_id');
            $table->timestamp('updated_at');
            $table->primary(['server_id', 'attempt_id']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('application_record_id')->references('id')->on('bps_application_records')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bps_source_heads');
    }
};
