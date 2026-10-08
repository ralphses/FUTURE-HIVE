<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_registrations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('school_name', 160);
            $table->string('school_type', 100);
            $table->string('state', 100);
            $table->string('contact_type', 16);
            $table->string('canonical_contact', 320);
            $table->string('consent_version', 64);
            $table->dateTime('consented_at');
            $table->string('status', 32)->default('pending_verification');
            $table->uuid('request_id')->nullable();
            $table->string('request_ip_hash', 64)->nullable();
            $table->string('request_user_agent_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['contact_type', 'canonical_contact']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement("CREATE UNIQUE INDEX school_registrations_active_contact_unique ON school_registrations (contact_type, canonical_contact) WHERE status <> 'cancelled'");
        } elseif (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::getConnection()->statement("CREATE UNIQUE INDEX school_registrations_active_contact_unique ON school_registrations (contact_type, canonical_contact) WHERE status != 'cancelled'");
        }
    }

    public function down(): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            Schema::getConnection()->statement('DROP INDEX IF EXISTS school_registrations_active_contact_unique');
        }

        Schema::dropIfExists('school_registrations');
    }
};
