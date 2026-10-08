<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('registration_id')->unique()->constrained('school_registrations')->restrictOnDelete();
            $table->foreignId('school_id')->nullable()->constrained('schools')->restrictOnDelete();
            $table->string('status', 24)->default('running');
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('request_id')->nullable()->index();
            $table->string('failure_code', 80)->nullable();
            $table->string('failure_message', 255)->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('school_setup_checklist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->string('item_key', 80);
            $table->string('status', 24)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_setup_checklist_items');
        Schema::dropIfExists('provisioning_runs');
    }
};
