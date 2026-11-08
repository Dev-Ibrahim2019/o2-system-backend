<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * صرفيات الأصناف (شاشة الأمين):
 *
 * - مخزن الوجهة لكل سطر (stock_document_lines.to_warehouse_id). فارغ = مخزن الرأس، فالصرفيات القديمة تبقى كما هي.
 * - الاستلام لكل مخزن وجهة (stock_document_receipts). المستند "مستلَم" عند اكتمال كل الوجهات (received_at
 *   على المستند كما كان)، و"مستلَم جزئياً" قبل ذلك. الصرفيات المستلَمة سابقاً تُنقل كسطر استلام لوجهتها.
 * - وقت الحركة في الرأس (document_time) للعرض والطباعة فقط — لحظة الحركات في الدفتر لم تتغير.
 * - "صرفية كميات فقط" (quantities_only): تُخفي الأسعار والإجماليات من الشاشة والطباعة، ولا تمس التكلفة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_document_lines', function (Blueprint $t) {
            $t->foreignId('to_warehouse_id')->nullable()->after('stock_item_id')->constrained('warehouses')->restrictOnDelete();
        });

        Schema::table('stock_documents', function (Blueprint $t) {
            $t->time('document_time')->nullable()->after('document_date');
            $t->boolean('quantities_only')->default(false)->after('notes');
        });

        Schema::create('stock_document_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_document_id')->constrained('stock_documents')->cascadeOnDelete();
            $t->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $t->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('received_at');
            $t->unique(['stock_document_id', 'warehouse_id']);
        });

        // ما أُكّد استلامه قبل هذه الخطوة: وجهة واحدة (مخزن الرأس).
        DB::statement(<<<'SQL'
            INSERT INTO stock_document_receipts (stock_document_id, warehouse_id, received_by, received_at)
            SELECT id, to_warehouse_id, received_by, received_at
            FROM stock_documents
            WHERE type = 'transfer' AND received_at IS NOT NULL AND to_warehouse_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_document_receipts');
        Schema::table('stock_documents', fn (Blueprint $t) => $t->dropColumn(['document_time', 'quantities_only']));
        Schema::table('stock_document_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('to_warehouse_id'));
    }
};
