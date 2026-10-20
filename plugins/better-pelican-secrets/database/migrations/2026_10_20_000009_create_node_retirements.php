<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bps_node_retirements', function (Blueprint $table) {
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('node_id');
            $table->unsignedBigInteger('through_record_id');
            $table->timestamp('updated_at');
            $table->primary(['server_id', 'node_id']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bps_node_retirements');
    }
};
