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
            'students.promotion.read' => ['label' => 'Read promotion cycles', 'description' => 'View human-approved promotion and repetition cycles.'],
            'students.promotion.manage' => ['label' => 'Manage promotion cycles', 'description' => 'Create cycles and propose explicit student decisions.'],
            'students.promotion.approve' => ['label' => 'Approve promotion cycles', 'description' => 'Approve proposed student promotion decisions.'],
            'students.promotion.apply' => ['label' => 'Apply promotion cycles', 'description' => 'Apply approved placement decisions transactionally.'],
        ];
        foreach ($permissions as $key => $attributes) {
            Permission::firstOrCreate(['key' => $key], $attributes);
        }
        $rolePermissions = [
            'school_admin' => array_keys($permissions),
            'proprietor' => array_keys($permissions),
            'principal' => ['students.promotion.read', 'students.promotion.approve', 'students.promotion.apply'],
        ];
        foreach ($rolePermissions as $roleKey => $keys) {
            $role = Role::query()->where('key', $roleKey)->first();
            if ($role !== null) {
                $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('key', $keys)->pluck('id'));
            }
        }
    }

    public function down(): void
    {
        $keys = ['students.promotion.read', 'students.promotion.manage', 'students.promotion.approve', 'students.promotion.apply'];
        Permission::query()->whereIn('key', $keys)->each(fn (Permission $permission): ?bool => $permission->delete());
    }
};
