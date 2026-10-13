<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('school_id')->constrained('users')->restrictOnDelete();
            $table->index(['user_id', 'school_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'school_id', 'status']);
            $table->dropColumn('user_id');
        });
    }
};
