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
            'academic.results.corrections.read' => ['Read result correction requests', ['school_admin', 'proprietor', 'principal', 'hod_reviewer']],
            'academic.results.corrections.manage' => ['Request result corrections', ['school_admin', 'proprietor', 'principal']],
            'academic.results.corrections.approve' => ['Approve result corrections', ['school_admin', 'proprietor', 'principal']],
            'academic.results.corrections.republish' => ['Republish corrected results', ['proprietor', 'principal']],
        ];

        foreach ($permissions as $key => [$label, $roles]) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['label' => $label, 'description' => $label, 'scope' => 'school', 'is_active' => true]);
            Role::query()->whereIn('key', $roles)->get()->each(fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching([$permission->getKey()]));
        }
    }

    public function down(): void
    {
        $keys = ['academic.results.corrections.read', 'academic.results.corrections.manage', 'academic.results.corrections.approve', 'academic.results.corrections.republish'];
        $permissions = Permission::query()->whereIn('key', $keys)->get();
        Role::query()->get()->each(fn (Role $role): mixed => $role->permissions()->detach($permissions->modelKeys()));
        Permission::query()->whereIn('key', $keys)->delete();
    }
};
