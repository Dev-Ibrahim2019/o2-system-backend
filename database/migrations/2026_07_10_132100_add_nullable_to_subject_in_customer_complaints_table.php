<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('customer_complaints')
            && Schema::hasColumn('customer_complaints', 'subject')) {
            DB::statement('ALTER TABLE customer_complaints MODIFY subject VARCHAR(255) NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customer_complaints')
            && Schema::hasColumn('customer_complaints', 'subject')) {
            DB::statement('ALTER TABLE customer_complaints MODIFY subject VARCHAR(255) NOT NULL');
        }
    }
};
