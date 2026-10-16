<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Services\AuthorizationCatalogue;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $keys = ['operations.reports.export', 'operations.reports.schedules.read', 'operations.reports.schedules.manage'];
        foreach ($keys as $key) {
            $definition = AuthorizationCatalogue::permissions()[$key];
            Permission::query()->firstOrCreate(['key' => $key], ['label' => $definition['label'], 'description' => $definition['description'], 'scope' => 'school', 'is_active' => true]);
        }
        $permissionIds = Permission::query()->whereIn('key', $keys)->pluck('id');
        Role::query()->whereIn('key', ['school_admin', 'principal', 'proprietor'])->each(fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching($permissionIds));
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['operations.reports.export', 'operations.reports.schedules.read', 'operations.reports.schedules.manage'])->get();
        Role::query()->whereIn('key', ['school_admin', 'principal', 'proprietor'])->each(fn (Role $role): mixed => $role->permissions()->detach($permissions->modelKeys()));
        Permission::query()->whereIn('id', $permissions->modelKeys())->delete();
    }
};
