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
        Schema::create('staff_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('school_membership_id')->constrained('school_memberships');
            $table->string('staff_number', 50);
            $table->string('legal_name', 160);
            $table->string('preferred_name', 160)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('department', 120)->nullable();
            $table->string('employment_status', 20)->default('pending');
            $table->date('employment_start_date')->nullable();
            $table->date('employment_end_date')->nullable();
            $table->string('status_reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'staff_number']);
            $table->unique(['school_id', 'school_membership_id']);
            $table->index(['school_id', 'employment_status']);
            $table->index(['school_membership_id', 'employment_status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE staff_profiles ADD CONSTRAINT staff_profiles_status_allowed CHECK (employment_status IN ('pending', 'active', 'suspended', 'ended'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_profiles');
    }
};
