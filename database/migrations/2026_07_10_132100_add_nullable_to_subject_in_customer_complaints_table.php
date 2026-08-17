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
        if (!Schema::hasColumn('customer_complaints', 'subject')) {
            Schema::table('customer_complaints', function (Blueprint $table) {
                $table->string('subject', 255)->nullable()->after('id');
            });
        }

        DB::statement('ALTER TABLE customer_complaints MODIFY subject VARCHAR(255) NULL');
    }

    public function down(): void
    {
        Schema::table('customer_complaints', function (Blueprint $table) {
            if (Schema::hasColumn('customer_complaints', 'subject')) {
                $table->dropColumn('subject');
            }
        });
    }
};
