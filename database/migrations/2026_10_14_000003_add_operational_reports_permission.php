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
        $permission = 'operations.reports.read';
        $definition = AuthorizationCatalogue::permissions()[$permission];
        $permissionModel = Permission::query()->firstOrCreate(['key' => $permission], [
            'name' => $permission,
            'label' => $definition['label'],
            'description' => $definition['description'],
            'scope' => 'school',
            'is_active' => true,
        ]);

        Role::query()->whereIn('key', ['school_admin', 'principal', 'proprietor'])->get()->each(
            fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching([$permissionModel->getKey()]),
        );
    }

    public function down(): void
    {
        $permission = Permission::query()->where('key', 'operations.reports.read')->first();

        if ($permission instanceof Permission) {
            Role::query()->get()->each(fn (Role $role): mixed => $role->permissions()->detach($permission->getKey()));
            $permission->delete();
        }
    }
};
