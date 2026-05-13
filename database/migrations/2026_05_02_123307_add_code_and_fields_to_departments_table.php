<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
             // Hierarchical code (e.g. "1", "11", "1101", "1102")
            $table->string('code', 20)->nullable()->unique()->after('name');

            // Arabic name (was missing from original migration)
            $table->string('nameAr', 255)->nullable()->after('code');

            // Status enum (ACTIVE / BUSY / INACTIVE)
            $table->enum('status', ['ACTIVE', 'BUSY', 'INACTIVE'])
                  ->default('ACTIVE')
                  ->after('is_active');

            // Physical location text
            $table->string('location', 255)->nullable()->after('stationNumber');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn(['code', 'nameAr', 'status', 'location']);
        });
    }
};
