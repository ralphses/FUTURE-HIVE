<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_guardian_relationships', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('guardian_profile_id')->constrained('guardian_profiles');
            $table->string('relationship_type', 32);
            $table->string('status', 32)->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['school_id', 'student_id', 'status']);
            $table->index(['school_id', 'guardian_profile_id', 'status']);
        });

        DB::statement('CREATE UNIQUE INDEX student_guardian_relationships_active_unique ON student_guardian_relationships (school_id, student_id, guardian_profile_id, relationship_type) WHERE status IN (\'pending\', \'active\')');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS student_guardian_relationships_active_unique');
        Schema::dropIfExists('student_guardian_relationships');
    }
};
