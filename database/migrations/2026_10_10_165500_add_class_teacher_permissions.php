<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['staff.class-teachers.read', 'staff.class-teachers.manage'] as $key) {
            Permission::query()->firstOrCreate(['key' => $key], ['name' => $key, 'description' => $key]);
        }

        Permission::query()->where('key', 'staff.class-teachers.read')->get()->each(function (Permission $permission): void {
            Role::query()->whereIn('key', ['school_admin', 'teacher', 'hod_reviewer', 'principal', 'bursar', 'proprietor', 'counsellor'])->get()->each(fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching([$permission->getKey()]));
        });
        Permission::query()->where('key', 'staff.class-teachers.manage')->get()->each(function (Permission $permission): void {
            Role::query()->whereIn('key', ['school_admin', 'proprietor'])->get()->each(fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching([$permission->getKey()]));
        });
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['staff.class-teachers.read', 'staff.class-teachers.manage'])->get();
        Role::query()->get()->each(fn (Role $role): mixed => $role->permissions()->detach($permissions->modelKeys()));
        Permission::query()->whereIn('key', ['staff.class-teachers.read', 'staff.class-teachers.manage'])->delete();
    }
};
