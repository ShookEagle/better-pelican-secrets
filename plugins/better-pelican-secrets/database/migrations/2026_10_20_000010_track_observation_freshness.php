<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bps_source_heads', function (Blueprint $table) {
            $table->timestamp('node_observed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bps_source_heads', function (Blueprint $table) {
            $table->dropColumn('node_observed_at');
        });
    }
};
