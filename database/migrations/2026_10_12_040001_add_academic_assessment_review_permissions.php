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
        foreach (['academic.scores.review', 'academic.scores.return', 'academic.scores.approve', 'academic.scores.resubmit'] as $key) {
            $definition = AuthorizationCatalogue::permissions()[$key];
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['label' => $definition['label'], 'description' => $definition['description'], 'scope' => 'school', 'is_active' => true],
            );
            $roles = in_array($key, ['academic.scores.review', 'academic.scores.return', 'academic.scores.approve'], true)
                ? ['school_admin', 'proprietor', 'principal', 'hod_reviewer']
                : ['school_admin', 'teacher', 'proprietor', 'principal', 'hod_reviewer'];
            Role::query()->whereIn('key', $roles)->get()->each(fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching([$permission->getKey()]));
        }
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['academic.scores.review', 'academic.scores.return', 'academic.scores.approve', 'academic.scores.resubmit'])->get();
        Role::query()->get()->each(fn (Role $role): mixed => $role->permissions()->detach($permissions->modelKeys()));
        Permission::query()->whereIn('key', ['academic.scores.review', 'academic.scores.return', 'academic.scores.approve', 'academic.scores.resubmit'])->delete();
    }
};
