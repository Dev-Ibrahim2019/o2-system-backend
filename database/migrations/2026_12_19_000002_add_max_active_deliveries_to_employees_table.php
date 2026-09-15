<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// حد أقصى اختياري لعدد التوصيلات النشطة المتزامنة لسائق معيّن — يُترك فارغًا افتراضيًا فيستخدم
// القيمة العامة بـ config('delivery.max_active_deliveries_per_driver') (راجع القسم 10 بالبرومبت:
// "read this limit from a config value... so it can be raised later without a redesign").
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'max_active_deliveries')) {
                $table->unsignedTinyInteger('max_active_deliveries')->nullable()->after('vehicle_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'max_active_deliveries')) {
                $table->dropColumn('max_active_deliveries');
            }
        });
    }
};
