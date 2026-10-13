<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_communication_threads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('guardian_profile_id')->constrained('guardian_profiles');
            $table->string('title', 160);
            $table->string('status', 24)->default('open');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'student_id', 'status']);
            $table->index(['school_id', 'guardian_profile_id', 'status']);
        });

        Schema::create('parent_communication_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('thread_id')->constrained('parent_communication_threads');
            $table->foreignId('author_id')->constrained('users');
            $table->text('body');
            $table->string('idempotency_key_hash', 64)->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->index(['school_id', 'thread_id', 'id']);
            $table->unique(['thread_id', 'author_id', 'idempotency_key_hash']);
        });

        Schema::create('parent_communication_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('thread_id')->constrained('parent_communication_threads');
            $table->foreignId('user_id')->constrained('users');
            $table->timestamp('read_at');
            $table->timestamps();
            $table->unique(['thread_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_communication_reads');
        Schema::dropIfExists('parent_communication_messages');
        Schema::dropIfExists('parent_communication_threads');
    }
};
