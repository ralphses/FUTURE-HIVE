<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $catalogue = [
            'counselling.assignments.read' => ['label' => 'Read counsellor assignments', 'description' => 'Read safe counsellor assignment metadata within the selected school.'],
            'counselling.assignments.manage' => ['label' => 'Manage counsellor assignments', 'description' => 'Assign and revoke counsellor-to-student access within the selected school.'],
        ];

        foreach ($catalogue as $key => $attributes) {
            Permission::query()->updateOrCreate(['key' => $key], $attributes);
        }

        foreach (['school_admin', 'principal'] as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->first();
            if ($role instanceof Role) {
                $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('key', array_keys($catalogue))->pluck('id'));
            }
        }

        $counsellorRole = Role::query()->where('key', 'counsellor')->first();
        if ($counsellorRole instanceof Role) {
            $counsellorRole->permissions()->syncWithoutDetaching(Permission::query()->where('key', 'counselling.assignments.read')->pluck('id'));
        }
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['counselling.assignments.read', 'counselling.assignments.manage'])->get();
        foreach ($permissions as $permission) {
            $permission->roles()->detach();
            $permission->delete();
        }
    }
};
