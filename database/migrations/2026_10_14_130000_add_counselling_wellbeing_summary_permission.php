<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $permission = [
            'key' => 'counselling.wellbeing-summaries.read',
            'label' => 'Read wellbeing summaries',
            'description' => 'View safe derived wellbeing summaries within authorized counselling scope.',
        ];

        if (! Schema::hasTable('permissions')) {
            return;
        }

        $table = 'permissions';
        $exists = DB::table($table)->where('key', $permission['key'])->exists();
        if (! $exists) {
            DB::table($table)->insert($permission + ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('key', 'counselling.wellbeing-summaries.read')->delete();
        }
    }
};
