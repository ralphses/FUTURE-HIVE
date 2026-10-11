<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'attendance.read' => 'Read daily attendance for authorized class arms.',
            'attendance.manage' => 'Record daily attendance for authorized class arms.',
        ];

        foreach ($permissions as $key => $description) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['name' => $key, 'description' => $description]);
            Role::query()->whereIn('key', ['school_admin', 'teacher', 'principal', 'proprietor'])->get()->each(fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching([$permission->getKey()]));
        }
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['attendance.read', 'attendance.manage'])->get();
        Role::query()->get()->each(fn (Role $role): mixed => $role->permissions()->detach($permissions->modelKeys()));
        Permission::query()->whereIn('key', ['attendance.read', 'attendance.manage'])->delete();
    }
};
