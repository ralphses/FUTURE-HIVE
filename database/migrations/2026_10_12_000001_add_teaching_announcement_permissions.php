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
            'teaching.announcements.read' => 'Read school teacher announcements.',
            'teaching.announcements.manage' => 'Create and manage scoped teacher announcements.',
            'teaching.announcements.review' => 'Review and approve teacher announcements.',
            'teaching.announcements.publish' => 'Publish and recall teacher announcements.',
        ];

        foreach ($permissions as $key => $description) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['name' => $key, 'description' => $description]);
            $roles = match ($key) {
                'teaching.announcements.review', 'teaching.announcements.publish' => ['school_admin', 'principal', 'proprietor', 'hod_reviewer'],
                default => ['school_admin', 'teacher', 'principal', 'proprietor', 'hod_reviewer'],
            };
            Role::query()->whereIn('key', $roles)->get()->each(fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching([$permission->getKey()]));
        }
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['teaching.announcements.read', 'teaching.announcements.manage', 'teaching.announcements.review', 'teaching.announcements.publish'])->get();
        Role::query()->get()->each(fn (Role $role): mixed => $role->permissions()->detach($permissions->modelKeys()));
        Permission::query()->whereIn('key', ['teaching.announcements.read', 'teaching.announcements.manage', 'teaching.announcements.review', 'teaching.announcements.publish'])->delete();
    }
};
