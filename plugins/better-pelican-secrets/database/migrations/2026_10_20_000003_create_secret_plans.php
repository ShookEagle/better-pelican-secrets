<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bps_managed_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('server_id');
            $table->text('source');
            $table->text('destination');
            $table->char('source_key', 64);
            $table->char('destination_key', 64);
            $table->string('format', 16);
            $table->string('default_mode', 16);
            $table->json('manifest');
            $table->char('source_digest', 64);
            $table->timestamps();
            $table->unique(['server_id', 'source_key']);
            $table->unique(['server_id', 'destination_key']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
        Schema::create('bps_startup_bindings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->string('env', 128)->collation(Schema::getConnection()->getDriverName() === 'sqlite' ? 'BINARY' : 'utf8mb4_bin');
            $table->uuid('secret_id');
            $table->timestamps();
            $table->unique(['server_id', 'env']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('secret_id')->references('id')->on('bps_secrets')->restrictOnDelete();
        });
        Schema::create('bps_desired_plan_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            // Text preserves the full uint64 revision on SQLite and MariaDB alike.
            $table->string('revision', 20);
            $table->json('payload');
            $table->timestamp('created_at');
            $table->unique(['server_id', 'revision']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bps_desired_plan_records');
        Schema::dropIfExists('bps_startup_bindings');
        Schema::dropIfExists('bps_managed_files');
    }
};
