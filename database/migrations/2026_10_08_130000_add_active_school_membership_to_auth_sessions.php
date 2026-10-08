<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table): void {
            $table->foreignId('active_school_membership_id')
                ->nullable()
                ->after('user_id')
                ->constrained('school_memberships')
                ->nullOnDelete();
            $table->index(['user_id', 'active_school_membership_id']);
        });
    }

    public function down(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table): void {
            $table->dropForeign(['active_school_membership_id']);
            $table->dropIndex(['user_id', 'active_school_membership_id']);
            $table->dropColumn('active_school_membership_id');
        });
    }
};
