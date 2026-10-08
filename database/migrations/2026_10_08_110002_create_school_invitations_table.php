<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_invitations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->foreignId('inviter_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('invitee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('invitee_contact_id')->constrained('user_contacts')->restrictOnDelete();
            $table->char('token_hash', 64);
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status']);
            $table->index(['invitee_id', 'status']);
            $table->index('expires_at');
        });

        DB::statement("CREATE UNIQUE INDEX school_invitations_pending_contact_unique ON school_invitations (school_id, invitee_contact_id) WHERE status = 'pending'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS school_invitations_pending_contact_unique');
        Schema::dropIfExists('school_invitations');
    }
};
