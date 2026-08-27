<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('accounts')) {
            return;
        }

        $assetParentId = DB::table('accounts')->where('code', '1130')->value('parent_id');

        $updates = [
            'type' => 'asset',
            'normal_balance' => 'debit',
            'name_en' => 'Employee Loans Receivable',
            'updated_at' => now(),
        ];

        if ($assetParentId) {
            $updates['parent_id'] = $assetParentId;
        }

        DB::table('accounts')->where('code', '2130')->update($updates);
    }

    public function down(): void
    {
        if (!Schema::hasTable('accounts')) {
            return;
        }

        $liabilityParentId = DB::table('accounts')->where('code', '2120')->value('parent_id');
        $updates = [
            'type' => 'liability',
            'normal_balance' => 'credit',
            'name_en' => 'Employee Loans',
            'updated_at' => now(),
        ];

        if ($liabilityParentId) {
            $updates['parent_id'] = $liabilityParentId;
        }

        DB::table('accounts')->where('code', '2130')->update($updates);
    }
};
