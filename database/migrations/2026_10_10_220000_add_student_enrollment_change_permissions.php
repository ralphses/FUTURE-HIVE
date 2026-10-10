<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Services\AuthorizationCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['students.enrollments.transfer', 'students.enrollments.withdraw'] as $key) {
            Permission::query()->firstOrCreate(['key' => $key], [
                'public_id' => (string) Str::uuid7(),
                'label' => AuthorizationCatalogue::permissions()[$key]['label'],
                'description' => AuthorizationCatalogue::permissions()[$key]['description'],
                'scope' => 'school',
                'is_active' => true,
            ]);
        }

        $permissions = DB::table('permissions')->whereIn('key', ['students.enrollments.transfer', 'students.enrollments.withdraw'])->pluck('id', 'key');
        $roles = DB::table('roles')->whereIn('key', ['school_admin', 'proprietor'])->pluck('id');
        foreach ($roles as $roleId) {
            foreach ($permissions as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('key', ['students.enrollments.transfer', 'students.enrollments.withdraw'])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
