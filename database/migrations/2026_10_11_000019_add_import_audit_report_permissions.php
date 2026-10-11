<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Services\AuthorizationCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = collect(AuthorizationCatalogue::permissions())->only(['imports.audit.read', 'imports.report.read']);
        foreach ($permissions as $key => $definition) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                ['label' => $definition['label'], 'description' => $definition['description'], 'scope' => 'school', 'is_active' => true],
            );
        }

        foreach (['school_admin', 'principal', 'proprietor'] as $roleKey) {
            $roleId = DB::table('roles')->where('key', $roleKey)->value('id');
            if ($roleId === null) {
                continue;
            }
            foreach ($permissions as $key => $definition) {
                $permissionId = DB::table('permissions')->where('key', $key)->value('id');
                DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', ['imports.audit.read', 'imports.report.read'])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
