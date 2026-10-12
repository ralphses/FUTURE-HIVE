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
        $definition = AuthorizationCatalogue::permissions()['academic.scores.audit.read'];
        $permission = Permission::query()->firstOrCreate(['key' => 'academic.scores.audit.read'], [
            'label' => $definition['label'],
            'description' => $definition['description'],
            'scope' => 'school',
            'is_active' => true,
        ]);

        foreach (['school_admin', 'proprietor', 'principal', 'hod_reviewer', 'teacher'] as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->first();
            $role?->permissions()->syncWithoutDetaching([$permission->getKey()]);
        }
    }

    public function down(): void
    {
        $permission = Permission::query()->where('key', 'academic.scores.audit.read')->first();
        if ($permission instanceof Permission) {
            Role::query()->get()->each(fn (Role $role): mixed => $role->permissions()->detach($permission->getKey()));
            $permission->delete();
        }
    }
};
