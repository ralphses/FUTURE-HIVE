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
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('student_number', 50);
            $table->string('display_name', 160);
            $table->date('admission_date');
            $table->string('status', 20)->default('pending');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'student_number']);
            $table->index(['school_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE students ADD CONSTRAINT students_status_allowed CHECK (status IN ('pending', 'active', 'withdrawn', 'archived'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
