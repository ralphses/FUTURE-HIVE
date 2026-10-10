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
        $permissions = AuthorizationCatalogue::permissions();

        foreach (['academic.grading-scales.read', 'academic.grading-scales.manage'] as $key) {
            $definition = $permissions[$key];
            Permission::query()->firstOrCreate(['key' => $key], ['label' => $definition['label'], 'description' => $definition['description'], 'scope' => 'school', 'is_active' => true]);
        }

        foreach (['school_admin', 'proprietor'] as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->firstOrFail();
            $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('key', ['academic.grading-scales.read', 'academic.grading-scales.manage'])->pluck('id'));
        }

        Permission::query()->where('key', 'academic.grading-scales.read')->get()->each(function (Permission $permission): void {
            Role::query()->whereIn('key', ['teacher', 'hod_reviewer', 'principal', 'bursar', 'counsellor', 'parent_guardian', 'student'])->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->getKey()]));
        });
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['academic.grading-scales.read', 'academic.grading-scales.manage'])->get();
        Role::query()->whereIn('key', ['school_admin', 'teacher', 'hod_reviewer', 'principal', 'bursar', 'proprietor', 'counsellor', 'parent_guardian', 'student'])->each(function (Role $role) use ($permissions): void {
            $role->permissions()->detach($permissions->modelKeys());
        });
        Permission::query()->whereIn('key', ['academic.grading-scales.read', 'academic.grading-scales.manage'])->delete();
    }
};
