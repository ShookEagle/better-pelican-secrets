<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bps_setup_failures', function (Blueprint $table) {
            $table->unsignedInteger('server_id');
            $table->uuid('file_id');
            $table->string('error_code', 64);
            $table->timestamp('updated_at');
            $table->primary(['server_id', 'file_id']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bps_setup_failures');
    }
};
