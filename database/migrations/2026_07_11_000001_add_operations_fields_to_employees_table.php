<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'operational_role')) {
                $table->string('operational_role', 40)->nullable()->after('role');
            }
            if (! Schema::hasColumn('employees', 'vehicle_type')) {
                $table->string('vehicle_type', 30)->nullable()->after('operational_role');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $columns = array_filter(
                ['vehicle_type', 'operational_role'],
                fn (string $column) => Schema::hasColumn('employees', $column)
            );
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
