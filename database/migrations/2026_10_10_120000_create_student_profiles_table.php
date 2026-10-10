<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->string('legal_name', 160);
            $table->string('preferred_name', 160)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 24)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique('student_id');
            $table->index(['school_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
