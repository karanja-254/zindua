<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('evidence_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['active', 'finalized', 'interrupted'])->default('active');
            $table->enum('risk_level', ['unassessed', 'low', 'medium', 'high'])->default('unassessed');
            $table->string('chain_hash', 64)->nullable();
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finalized_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('evidence_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('session_id')->constrained('evidence_sessions')->cascadeOnDelete();
            $table->unsignedInteger('sequence_number');
            $table->string('storage_path');
            $table->unsignedBigInteger('byte_size');
            $table->string('chunk_hash', 64);
            $table->string('cumulative_hash', 64);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->timestampTz('captured_at');
            $table->timestampsTz();

            $table->unique(['session_id', 'sequence_number']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('session_id')->constrained('evidence_sessions')->cascadeOnDelete();
            $table->string('actor_ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('action');
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('evidence_chunks');
        Schema::dropIfExists('evidence_sessions');
    }
};
