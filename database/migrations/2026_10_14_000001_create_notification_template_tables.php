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
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('template_key', 80);
            $table->string('channel', 20);
            $table->unsignedInteger('version');
            $table->string('title', 160);
            $table->text('body');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'template_key', 'channel', 'version'], 'notification_template_version_unique');
            $table->index(['school_id', 'template_key', 'channel', 'status'], 'notification_template_lookup_index');
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('notification_template_id')->constrained('notification_templates');
            $table->string('channel', 20);
            $table->uuid('recipient_public_id');
            $table->char('recipient_reference_hash', 64);
            $table->string('recipient_type', 20);
            $table->string('source_type', 30)->default('manual');
            $table->uuid('source_public_id')->nullable();
            $table->char('idempotency_hash', 64);
            $table->string('status', 20)->default('queued');
            $table->string('failure_code', 80)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->string('request_id', 100)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'idempotency_hash'], 'notification_delivery_idempotency_unique');
            $table->index(['school_id', 'status', 'created_at'], 'notification_delivery_status_index');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE notification_templates ADD CONSTRAINT notification_template_status_allowed CHECK (status IN ('draft', 'active', 'retired'))");
            DB::statement("ALTER TABLE notification_templates ADD CONSTRAINT notification_template_channel_allowed CHECK (channel IN ('in_app', 'email', 'sms', 'push'))");
            DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_delivery_status_allowed CHECK (status IN ('queued', 'sent', 'failed', 'cancelled'))");
            DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_delivery_channel_allowed CHECK (channel IN ('in_app', 'email', 'sms', 'push'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_templates');
    }
};
