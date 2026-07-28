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
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('customer_complaints', function (Blueprint $table) {
                $table->string('title')->nullable()->change();
            });
            return;
        }

        DB::statement('ALTER TABLE customer_complaints MODIFY title VARCHAR(255) NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('customer_complaints', function (Blueprint $table) {
                $table->string('title')->nullable(false)->change();
            });
            return;
        }

        DB::statement('ALTER TABLE customer_complaints MODIFY title VARCHAR(255) NOT NULL');
    }
};
