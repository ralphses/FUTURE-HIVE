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
            'academic.assessments.read' => 'Read assessment instances in the selected school.',
            'academic.assessments.manage' => 'Create and manage assessment instances in the selected school.',
        ];

        foreach ($permissions as $key => $description) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['name' => $key, 'description' => $description]);
            $roles = $key === 'academic.assessments.manage'
                ? ['school_admin', 'proprietor']
                : ['school_admin', 'teacher', 'hod_reviewer', 'principal', 'proprietor', 'bursar', 'counsellor', 'parent_guardian', 'student'];
            Role::query()->whereIn('key', $roles)->get()->each(fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching([$permission->getKey()]));
        }
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['academic.assessments.read', 'academic.assessments.manage'])->get();
        Role::query()->get()->each(fn (Role $role): mixed => $role->permissions()->detach($permissions->modelKeys()));
        Permission::query()->whereIn('key', ['academic.assessments.read', 'academic.assessments.manage'])->delete();
    }
};
