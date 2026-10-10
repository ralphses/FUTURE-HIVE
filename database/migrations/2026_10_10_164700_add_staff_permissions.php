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
        foreach (['staff.read', 'staff.manage', 'staff.employment.manage'] as $key) {
            $definition = AuthorizationCatalogue::permissions()[$key];
            Permission::query()->firstOrCreate(['key' => $key], ['label' => $definition['label'], 'description' => $definition['description'], 'scope' => 'school', 'is_active' => true]);
        }

        Permission::query()->where('key', 'staff.read')->get()->each(function (Permission $permission): void {
            Role::query()->whereIn('key', ['school_admin', 'teacher', 'hod_reviewer', 'principal', 'bursar', 'proprietor', 'counsellor'])->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->getKey()]));
        });

        Permission::query()->whereIn('key', ['staff.manage', 'staff.employment.manage'])->get()->each(function (Permission $permission): void {
            Role::query()->whereIn('key', ['school_admin', 'proprietor'])->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->getKey()]));
        });
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['staff.read', 'staff.manage', 'staff.employment.manage'])->get();
        Role::query()->whereIn('key', ['school_admin', 'teacher', 'hod_reviewer', 'principal', 'bursar', 'proprietor', 'counsellor'])->each(fn (Role $role) => $role->permissions()->detach($permissions->modelKeys()));
        Permission::query()->whereIn('key', ['staff.read', 'staff.manage', 'staff.employment.manage'])->delete();
    }
};
