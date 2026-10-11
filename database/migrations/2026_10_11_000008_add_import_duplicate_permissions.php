<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Services\AuthorizationCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = collect(AuthorizationCatalogue::permissions())->only(['imports.duplicates.read', 'imports.duplicates.manage']);
        foreach ($permissions as $key => $definition) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['label' => $definition['label'], 'description' => $definition['description'], 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach (['school_admin', 'principal', 'proprietor'] as $roleKey) {
            $roleId = DB::table('roles')->where('key', $roleKey)->value('id');
            if ($roleId === null) {
                continue;
            }
            foreach ($permissions as $key => $definition) {
                $permissionId = DB::table('permissions')->where('key', $key)->value('id');
                DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId], ['created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        $ids = DB::table('permissions')->whereIn('key', ['imports.duplicates.read', 'imports.duplicates.manage'])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
