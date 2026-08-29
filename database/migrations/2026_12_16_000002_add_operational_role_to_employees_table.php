<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // المهمة التشغيلية الفعلية للموظف — كانت مستخدَمة بالواجهة بدون عمود مطابق
            // في القاعدة، فكل موظف كان يُعرَض بالقيمة الافتراضية "دور آخر" دائماً.
            $table->string('operational_role', 30)->default('other')->after('role');
            $table->string('vehicle_type', 30)->nullable()->after('operational_role');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['operational_role', 'vehicle_type']);
        });
    }
};
