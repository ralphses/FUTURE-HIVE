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
        $definitions = AuthorizationCatalogue::permissions();
        foreach (['students.enrollments.read', 'students.enrollments.manage'] as $key) {
            $definition = $definitions[$key];
            Permission::query()->firstOrCreate(['key' => $key], [
                'label' => $definition['label'],
                'description' => $definition['description'],
                'scope' => 'school',
                'is_active' => true,
            ]);
        }

        $read = Permission::query()->where('key', 'students.enrollments.read')->firstOrFail();
        Role::query()->whereIn('key', array_keys(AuthorizationCatalogue::roles()))->each(
            fn (Role $role) => $role->permissions()->syncWithoutDetaching([$read->getKey()])
        );

        $manage = Permission::query()->where('key', 'students.enrollments.manage')->firstOrFail();
        Role::query()->whereIn('key', ['school_admin', 'proprietor'])->each(
            fn (Role $role) => $role->permissions()->syncWithoutDetaching([$manage->getKey()])
        );
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['students.enrollments.read', 'students.enrollments.manage'])->get();
        Role::query()->whereIn('key', array_keys(AuthorizationCatalogue::roles()))->each(
            fn (Role $role) => $role->permissions()->detach($permissions->modelKeys())
        );
        Permission::query()->whereIn('key', ['students.enrollments.read', 'students.enrollments.manage'])->delete();
    }
};
