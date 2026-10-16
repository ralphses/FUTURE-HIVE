<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['counselling.referrals.read', 'Read counselling referrals', 'View safe referral metadata within authorized counselling scope.'],
            ['counselling.referrals.manage', 'Manage counselling referrals', 'Create and lifecycle-manage authorized counselling referrals.'],
            ['counselling.referrals.approve', 'Approve counselling referrals', 'Approve or reject counselling referrals with maker-checker separation.'],
            ['counselling.escalations.read', 'Read safeguarding escalations', 'View safe escalation metadata within authorized safeguarding scope.'],
            ['counselling.escalations.manage', 'Manage safeguarding escalations', 'Create and lifecycle-manage authorized safeguarding escalations.'],
            ['counselling.escalations.approve', 'Approve safeguarding escalations', 'Approve or reject safeguarding escalations with maker-checker separation.'],
        ];

        foreach ($permissions as [$key, $label, $description]) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['label' => $label, 'description' => $description, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('key', [
            'counselling.referrals.read', 'counselling.referrals.manage', 'counselling.referrals.approve',
            'counselling.escalations.read', 'counselling.escalations.manage', 'counselling.escalations.approve',
        ])->delete();
    }
};
