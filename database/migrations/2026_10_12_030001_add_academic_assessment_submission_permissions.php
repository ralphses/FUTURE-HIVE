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
        foreach (['academic.scores.submit', 'academic.scores.submission.read'] as $key) {
            $definition = AuthorizationCatalogue::permissions()[$key];
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['label' => $definition['label'], 'description' => $definition['description'], 'scope' => 'school', 'is_active' => true],
            );
            $roles = $key === 'academic.scores.submit'
                ? ['school_admin', 'teacher', 'hod_reviewer', 'principal', 'proprietor']
                : ['school_admin', 'teacher', 'hod_reviewer', 'principal', 'proprietor'];
            Role::query()->whereIn('key', $roles)->get()->each(fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching([$permission->getKey()]));
        }
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['academic.scores.submit', 'academic.scores.submission.read'])->get();
        Role::query()->get()->each(fn (Role $role): mixed => $role->permissions()->detach($permissions->modelKeys()));
        Permission::query()->whereIn('key', ['academic.scores.submit', 'academic.scores.submission.read'])->delete();
    }
};
