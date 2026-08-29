<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evidence_chunks', function (Blueprint $table): void {
            if (! Schema::hasColumn('evidence_chunks', 'mime_type')) {
                $table->string('mime_type', 127)->nullable()->after('storage_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('evidence_chunks', function (Blueprint $table): void {
            if (Schema::hasColumn('evidence_chunks', 'mime_type')) {
                $table->dropColumn('mime_type');
            }
        });
    }
};
