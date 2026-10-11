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
            'teaching.schemes.read' => 'Read school schemes of work.',
            'teaching.schemes.manage' => 'Manage school schemes of work and topic progress.',
            'teaching.lessons.read' => 'Read school lesson records.',
            'teaching.lessons.manage' => 'Create and manage assigned teacher lesson records.',
            'teaching.lessons.review' => 'Review and approve or return submitted lesson records.',
        ];
        foreach ($permissions as $key => $description) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['name' => $key, 'description' => $description]);
            $roles = match ($key) {
                'teaching.schemes.manage' => ['school_admin', 'teacher', 'principal', 'proprietor', 'hod_reviewer'],
                'teaching.lessons.manage' => ['school_admin', 'teacher', 'principal', 'proprietor', 'hod_reviewer'],
                'teaching.lessons.review' => ['school_admin', 'principal', 'proprietor', 'hod_reviewer'],
                default => ['school_admin', 'teacher', 'principal', 'proprietor', 'hod_reviewer'],
            };
            Role::query()->whereIn('key', $roles)->get()->each(fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching([$permission->getKey()]));
        }
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['teaching.schemes.read', 'teaching.schemes.manage', 'teaching.lessons.read', 'teaching.lessons.manage', 'teaching.lessons.review'])->get();
        Role::query()->get()->each(fn (Role $role): mixed => $role->permissions()->detach($permissions->modelKeys()));
        Permission::query()->whereIn('key', ['teaching.schemes.read', 'teaching.schemes.manage', 'teaching.lessons.read', 'teaching.lessons.manage', 'teaching.lessons.review'])->delete();
    }
};
