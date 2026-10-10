<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardian_invitations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('guardian_profile_id')->constrained('guardian_profiles');
            $table->foreignId('relationship_id')->constrained('student_guardian_relationships');
            $table->string('code_hash', 64);
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 64)->nullable();
            $table->string('request_ip_hash', 64)->nullable();
            $table->string('request_user_agent_hash', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['school_id', 'relationship_id', 'expires_at']);
            $table->index(['guardian_profile_id', 'consumed_at', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_invitations');
    }
};
