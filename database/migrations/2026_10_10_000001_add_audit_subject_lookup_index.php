<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->index(['school_id', 'subject_type', 'subject_public_id', 'created_at'], 'audit_events_school_subject_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex('audit_events_school_subject_created_index');
        });
    }
};
