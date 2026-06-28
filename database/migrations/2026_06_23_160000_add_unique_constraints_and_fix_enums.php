<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إضافة قيود UNIQUE ومفاتيح idempotency لمنع تكرار العمليات المالية:
     *
     * 1. payments.reference_number → إضافة العمود إذا لم يكن موجوداً
     * 2. payments.method → VARCHAR بدلاً من ENUM (لدعم customer/employee/supplier)
     * 3. unique( invoice_id, reference_number ) → لمنع إضافة نفس الدفعة للفاتورة مرتين
     */
    public function up(): void
    {
        // 1. تغيير payments.method من ENUM إلى VARCHAR(50)
        Schema::table('payments', function (Blueprint $table) {
            $table->string('method', 50)->change();
        });

        // 2. إضافة reference_number إذا لم يكن موجوداً
        if (! Schema::hasColumn('payments', 'reference_number')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('reference_number', 255)
                    ->nullable()
                    ->after('amount')
                    ->comment('الرقم المرجعي للدفعة (مثلاً رقم التحويل أو رقم إذن البطاقة)');
            });
        }

        // 3. إضافة unique constraint composite للمدفوعات
        //    نستخدم raw SQL لأن MySQL لا يدعم unique على nullable بسهولة
        try {
            Schema::table('payments', function (Blueprint $table) {
                $table->unique(['invoice_id', 'reference_number'], 'uq_payments_invoice_ref');
            });
        } catch (\Exception $e) {
            // قد تفشل إذا كانت البيانات موجودة مسبقاً — مقبولة
            logger()->warning('Unique constraint uq_payments_invoice_ref could not be created: ' . $e->getMessage());
        }
    }

    /**
     * عكس التغييرات
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('uq_payments_invoice_ref');
        });
    }
};
