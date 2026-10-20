<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bps_secret_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('server_id');
            // Empty sentinel makes server-wide uniqueness reliable on both supported databases.
            // Non-empty IDs are validated against server ownership before every mutation/use.
            $table->string('secret_id', 36)->default('');
            $table->string('operation', 16);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamp('created_at');
            $table->unique(['user_id', 'server_id', 'secret_id', 'operation'], 'bps_grants_scope_operation_unique');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bps_secret_grants');
    }
};
