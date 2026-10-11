<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Permission::query()->firstOrCreate(
            ['key' => 'attendance.reports.read'],
            ['description' => 'Read attendance summaries for authorized class arms.'],
        );

        $permissionId = Permission::query()->where('key', 'attendance.reports.read')->value('id');
        $roleIds = Role::query()->whereIn('key', ['school_admin', 'teacher', 'principal', 'proprietor'])->pluck('id');

        foreach ($roleIds as $roleId) {
            Permission::query()->findOrFail($permissionId)->roles()->syncWithoutDetaching([$roleId]);
        }
    }

    public function down(): void
    {
        Permission::query()->where('key', 'attendance.reports.read')->delete();
    }
};
