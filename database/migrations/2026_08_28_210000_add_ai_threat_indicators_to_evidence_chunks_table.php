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
        Schema::table('evidence_chunks', function (Blueprint $table): void {
            $table->json('ai_threat_indicators')->nullable()->after('accuracy_meters');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('evidence_chunks', function (Blueprint $table): void {
            $table->dropColumn('ai_threat_indicators');
        });
    }
};
