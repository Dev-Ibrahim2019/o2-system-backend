<?php

// ══════════════════════════════════════════════════════════════════════
// FILE: database/migrations/2026_06_01_000001_redesign_accounts_table.php
// ══════════════════════════════════════════════════════════════════════
// يُضاف هذا Migration فوق الـ accounts table الحالية
// أو يُستبدل بها في fresh install

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ── إذا كانت الجداول موجودة بالفعل، نعدّل عليها ──
        Schema::table('accounts', function (Blueprint $table) {

            // اسم بالعربية واسم بالإنجليزية — ضروري في ERP متعدد اللغات
            if (! Schema::hasColumn('accounts', 'name_en')) {
                $table->string('name_en')->nullable()->after('name');
            }

            // الكود الكامل (محمي من التكرار)
            // unique index موجود مسبقاً

            // ربط الحساب بكيان (entity_type + entity_id)
            // بدلاً من polymorphic — لأن المحاسبة تحتاج استعلامات مباشرة بالكود
            if (! Schema::hasColumn('accounts', 'entity_type')) {
                $table->enum('entity_type', ['employee', 'customer', 'supplier', 'branch'])
                    ->nullable()
                    ->after('notes');
            }
            if (! Schema::hasColumn('accounts', 'entity_id')) {
                $table->unsignedBigInteger('entity_id')
                    ->nullable()
                    ->after('entity_type');
            }

            // نوع فرعي للتمييز داخل نفس entity_type
            // مثال: employee + advance | employee + salary
            if (! Schema::hasColumn('accounts', 'sub_type')) {
                $table->string('sub_type', 50)->nullable()->after('entity_id');
            }

            // بيانات إضافية (meta) بصيغة JSON لأي خصائص مستقبلية
            if (! Schema::hasColumn('accounts', 'meta')) {
                $table->json('meta')->nullable()->after('sub_type');
            }

            // العملة — لدعم multi-currency مستقبلاً
            if (! Schema::hasColumn('accounts', 'currency')) {
                $table->char('currency', 3)->default('ILS')->after('meta');
            }

            // الفرع — إذا كانت الحسابات مقسمة بالفروع
            if (! Schema::hasColumn('accounts', 'branch_id')) {
                $table->foreignId('branch_id')
                    ->nullable()
                    ->after('currency')
                    ->constrained('branches')
                    ->nullOnDelete();
            }
        });

        // ── فهرسة مركّبة لمنع تكرار الحساب لنفس الكيان ──────────────
        Schema::table('accounts', function (Blueprint $table) {
            $table->index(['entity_type', 'entity_id', 'sub_type'], 'accounts_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex('accounts_entity_idx');
            $table->dropColumn([
                'name_en',
                'entity_type',
                'entity_id',
                'sub_type',
                'meta',
                'currency',
                'branch_id',
            ]);
        });
    }
};
