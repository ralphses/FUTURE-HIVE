<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->foreignId('academic_assessment_instance_id')->nullable()->after('school_id')->constrained('academic_assessment_instances');
            $table->index(['school_id', 'academic_assessment_instance_id'], 'import_batches_assessment_scope_index');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropIndex('import_batches_assessment_scope_index');
            $table->dropConstrainedForeignId('academic_assessment_instance_id');
        });
    }
};
