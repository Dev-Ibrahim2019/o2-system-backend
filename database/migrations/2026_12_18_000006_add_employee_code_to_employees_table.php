<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// كود داخلي فريد لسائقي التوصيل (DR-###) — منفصل عن employeeId العام الموجود أصلاً (ذاك اختياري
// وغير مُوحَّد الصيغة)، مطلوب صراحة لصفحة إدارة الديليفري الجديدة.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'employee_code')) {
                $table->string('employee_code')->nullable()->unique()->after('employeeId');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'employee_code')) {
                $table->dropUnique(['employee_code']);
                $table->dropColumn('employee_code');
            }
        });
    }
};
