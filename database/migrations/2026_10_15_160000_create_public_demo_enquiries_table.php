<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_demo_enquiries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name', 160);
            $table->string('organization', 160)->nullable();
            $table->string('role', 100)->nullable();
            $table->string('contact_type', 20);
            $table->char('contact_hash', 64);
            $table->string('category', 40);
            $table->text('message');
            $table->string('consent_version', 64);
            $table->timestamp('consented_at');
            $table->string('status', 20);
            $table->char('idempotency_key_hash', 64)->unique();
            $table->char('fingerprint_hash', 64);
            $table->string('request_id', 100)->nullable();
            $table->char('request_ip_hash', 64)->nullable();
            $table->char('request_user_agent_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['contact_type', 'contact_hash']);
            $table->index(['status', 'category']);
            $table->index('fingerprint_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_demo_enquiries');
    }
};
