<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_id')->constrained('discounts')->cascadeOnDelete();

            // نوع المستهدف: customer, employee, supplier, department, item, customer_group, employee_group, supplier_group, all_customers, all_employees, all_suppliers, all
            $table->string('target_type', 50);
            // معرف المستهدف (null للأنواع العامة مثل all_customers)
            $table->unsignedBigInteger('target_id')->nullable();
            // فهرس لدعم البحث
            $table->index(['target_type', 'target_id']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_targets');
    }
};
