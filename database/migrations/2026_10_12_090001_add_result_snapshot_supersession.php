<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_result_snapshots', function (Blueprint $table): void {
            $table->foreignId('supersedes_snapshot_id')->nullable()->after('academic_result_run_id')->constrained('academic_result_snapshots');
        });

    }

    public function down(): void
    {
        Schema::table('academic_result_snapshots', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supersedes_snapshot_id');
        });
    }
};
