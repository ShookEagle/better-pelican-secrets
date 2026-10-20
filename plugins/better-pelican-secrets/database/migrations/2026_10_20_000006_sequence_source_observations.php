<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bps_source_heads', function (Blueprint $table) {
            $table->string('node_sequence', 20)->default('0');
            $table->unsignedBigInteger('node_record_id')->nullable();
            $table->foreign('node_record_id')->references('id')->on('bps_application_records')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bps_source_heads', function (Blueprint $table) {
            $table->dropForeign(['node_record_id']);
            $table->dropColumn(['node_sequence', 'node_record_id']);
        });
    }
};
