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
            'platform.billing.read' => ['label' => 'Read SaaS billing', 'description' => 'View safe platform SaaS invoices and payment state.'],
            'platform.billing.manage' => ['label' => 'Manage SaaS billing', 'description' => 'Create, issue and void platform SaaS invoices.'],
            'platform.billing.payments.manage' => ['label' => 'Initiate SaaS payments', 'description' => 'Create provider-neutral SaaS payment records.'],
            'platform.billing.payments.verify' => ['label' => 'Verify SaaS payments', 'description' => 'Verify signed SaaS payment outcomes and replay protection.'],
            'platform.billing.refunds.manage' => ['label' => 'Manage SaaS refunds', 'description' => 'Record approved SaaS payment refunds.'],
        ];

        foreach ($catalogue as $key => $attributes) {
            Permission::query()->updateOrCreate(['key' => $key], [...$attributes, 'scope' => 'platform', 'is_active' => true]);
        }

        $all = Permission::query()->whereIn('key', array_keys($catalogue))->pluck('id')->all();
        Role::query()->whereIn('key', ['platform_admin'])->each(fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching($all));
        Role::query()->whereIn('key', ['platform_ops'])->each(fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching($all));
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['platform.billing.read', 'platform.billing.manage', 'platform.billing.payments.manage', 'platform.billing.payments.verify', 'platform.billing.refunds.manage'])->get();
        foreach ($permissions as $permission) {
            $permission->roles()->detach();
            $permission->delete();
        }
    }
};
