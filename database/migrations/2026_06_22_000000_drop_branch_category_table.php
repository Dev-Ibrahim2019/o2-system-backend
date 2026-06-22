<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branch_category')) {
            return;
        }

        if (Schema::hasTable('branch_department')) {
            DB::table('branch_category')
                ->orderBy('id')
                ->chunk(500, function ($rows) {
                    foreach ($rows as $row) {
                        DB::table('branch_department')->updateOrInsert(
                            [
                                'branch_id' => $row->branch_id,
                                'department_id' => $row->category_id,
                            ],
                            [
                                'is_active' => $row->is_active,
                                'created_at' => $row->created_at,
                                'updated_at' => $row->updated_at,
                            ]
                        );
                    }
                });
        }

        Schema::dropIfExists('branch_category');
    }

    public function down(): void
    {
        //
    }
};
