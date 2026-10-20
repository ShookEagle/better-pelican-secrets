<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bps_secrets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('server_id');
            $table->string('name', 64)->collation(Schema::getConnection()->getDriverName() === 'sqlite' ? 'BINARY' : 'utf8mb4_bin');
            $table->text('description');
            $table->unsignedInteger('current_version');
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['server_id', 'name']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::create('bps_secret_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('secret_id');
            $table->unsignedInteger('version');
            $table->longText('encrypted_value');
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamp('created_at');
            $table->unique(['secret_id', 'version']);
            $table->foreign('secret_id')->references('id')->on('bps_secrets')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
        if (!Schema::hasTable('bps_secret_audit_events')) {
            Schema::create('bps_secret_audit_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('actor_id')->nullable();
                $table->string('operation', 64);
                $table->json('metadata');
                $table->timestamp('created_at');
                // Audit survives secret, server, actor deletion, and native uninstall.
            });
        }
    }

    public function down(): void
    {
        // Audit is independent metadata retained under the configured policy.
        Schema::dropIfExists('bps_secret_versions');
        Schema::dropIfExists('bps_secrets');
    }
};
