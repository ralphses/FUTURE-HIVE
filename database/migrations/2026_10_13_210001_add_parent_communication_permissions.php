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
        $keys = ['communications.parent.read', 'communications.parent.manage', 'communications.staff.read', 'communications.staff.manage'];
        foreach ($keys as $key) {
            $definition = AuthorizationCatalogue::permissions()[$key];
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['name' => $key, 'description' => $definition['description'], 'scope' => 'school', 'is_active' => true]);
            $roles = match ($key) {
                'communications.parent.read', 'communications.parent.manage' => ['parent_guardian'],
                default => ['school_admin', 'principal', 'proprietor', 'hod_reviewer', 'teacher'],
            };
            Role::query()->whereIn('key', $roles)->get()->each(fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching([$permission->getKey()]));
        }
    }

    public function down(): void
    {
        $keys = ['communications.parent.read', 'communications.parent.manage', 'communications.staff.read', 'communications.staff.manage'];
        $permissions = Permission::query()->whereIn('key', $keys)->get();
        Role::query()->get()->each(fn (Role $role): mixed => $role->permissions()->detach($permissions->modelKeys()));
        Permission::query()->whereIn('key', $keys)->delete();
    }
};
