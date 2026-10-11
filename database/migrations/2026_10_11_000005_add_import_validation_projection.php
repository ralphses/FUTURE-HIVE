<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_staged_rows', function (Blueprint $table): void {
            $table->json('normalized_cells')->nullable()->after('cells');
        });

        DB::table('import_staged_rows')->where('status', 'staged')->update(['status' => 'pending']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE import_staged_rows DROP CONSTRAINT IF EXISTS import_staged_rows_status_allowed');
            DB::statement("ALTER TABLE import_staged_rows ADD CONSTRAINT import_staged_rows_status_allowed CHECK (status IN ('pending', 'valid', 'invalid'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE import_staged_rows DROP CONSTRAINT IF EXISTS import_staged_rows_status_allowed');
            DB::statement("ALTER TABLE import_staged_rows ADD CONSTRAINT import_staged_rows_status_allowed CHECK (status IN ('staged', 'failed'))");
        }

        Schema::table('import_staged_rows', function (Blueprint $table): void {
            $table->dropColumn('normalized_cells');
        });
    }
};
