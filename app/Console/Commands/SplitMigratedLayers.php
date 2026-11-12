<?php

namespace App\Console\Commands;

use App\Models\StockDocument;
use App\Models\StockLayer;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\UnitConverter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * تقسيم دفعات ترحيل الأرصدة إلى توريداتها الأصلية: الرصيد الذي رُحِّل دفعة "رصيد أول المدة" واحدة يُعاد بناؤه من
 * أحدث الواردات (الأقدم أولاً يعني أن الباقي في المخزن هو آخر ما ورد): كل فاتورة/استلام/تحويل/أول مدة دفعة مستقلة
 * بسعرها ومرجعها ومورّدها وتاريخها. يشمل فقط دفعات الترحيل التي لم يُصرف منها بعد؛ فرق القيمة حركة "إعادة تقييم".
 */
class SplitMigratedLayers extends Command
{
    protected $signature = 'inventory:split-migrated-layers {--dry-run : عرض التقسيم بلا تسجيل}';

    protected $description = 'تقسيم دفعات ترحيل الأرصدة إلى التوريدات الأصلية حتى يُصرف من كل توريدة بسعرها';

    private const S = UnitConverter::SCALE;

    public function handle(StockLedger $ledger): int
    {
        $dry = (bool) $this->option('dry-run');
        $layers = StockLayer::where('source_type', 'opening_balance')->whereNull('movement_id')->whereColumn('qty_remaining', 'qty_in')
            ->whereNotExists(fn ($q) => $q->from('stock_layer_allocations as a')->whereColumn('a.layer_id', 'stock_layers.id'))
            ->orderBy('id')->get();
        $rows = [];
        foreach ($layers as $layer) {
            DB::transaction(function () use ($layer, $ledger, $dry, &$rows) {
                $w = (int) $layer->warehouse_id;
                $i = (int) $layer->stock_item_id;
                $ledger->lockPair($w, $i);
                $parts = $this->tail($layer);
                foreach ($parts as $p) {
                    $rows[] = [$w, $i, $p['ref'], $p['take'], $p['unit_cost']];
                }
                if ($dry || $parts === []) {
                    return;
                }
                $before = $ledger->layers()->value($w, $i);
                foreach ($parts as $n => $p) {
                    $attrs = [
                        'source_type' => $p['source_type'], 'source_id' => $p['source_id'], 'source_line_id' => $p['source_line_id'],
                        'movement_id' => $p['movement_id'], 'purchase_bill_id' => $p['purchase_bill_id'], 'supplier_id' => $p['supplier_id'],
                        'received_at' => $p['received_at'], 'qty_in' => $p['qty_in'], 'qty_remaining' => $p['take'], 'unit_cost' => $p['unit_cost'],
                    ];
                    // الدفعة الأولى تأخذ مكان دفعة الترحيل (الدفعات لا تُحذف)، والباقي دفعات جديدة.
                    $n === 0 ? $layer->forceFill($attrs)->save() : StockLayer::create([...$attrs, 'warehouse_id' => $w, 'stock_item_id' => $i]);
                }
                $after = $ledger->layers()->value($w, $i);
                $ledger->postValueAdjustment($w, $i, 'revaluation', bcsub($after, $before, self::S), null, null, null,
                    'تقسيم رصيد الترحيل إلى توريداته', null, $after);
            });
        }
        $this->table(['المخزن', 'الصنف', 'التوريدة', 'الكمية', 'السعر'], $rows);
        $this->info(($dry ? 'معاينة: ' : 'تم: ').count($layers).' دفعة ترحيل.');

        return self::SUCCESS;
    }

    /** أحدث الواردات (غير المعكوسة) قبل الترحيل التي تغطي الرصيد؛ الباقي بلا مصدر يبقى أول مدة. الأقدم أولاً. */
    private function tail(StockLayer $layer): array
    {
        $left = (string) $layer->qty_in;
        $parts = [];
        $incoming = DB::table('stock_movements as m')
            ->where('m.warehouse_id', $layer->warehouse_id)->where('m.stock_item_id', $layer->stock_item_id)
            ->where('m.quantity', '>', 0)->whereNull('m.reversal_of_id')->where('m.created_at', '<=', $layer->created_at)
            ->whereNotExists(fn ($q) => $q->from('stock_movements as r')->whereColumn('r.reversal_of_id', 'm.id'))
            ->orderByDesc('m.occurred_at')->orderByDesc('m.id')->get();
        foreach ($incoming as $m) {
            if (bccomp($left, '0', self::S) <= 0) {
                break;
            }
            $take = bccomp($left, (string) $m->quantity, self::S) <= 0 ? $left : (string) $m->quantity;
            // الوارد كاملاً كما سُجّل والمتبقي منه فقط: الجزء المصروف قديماً يمنع عكس الوارد (كبقية الدفعات).
            $parts[] = [...$this->source($m), 'movement_id' => $m->id, 'received_at' => $m->occurred_at,
                'qty_in' => (string) $m->quantity, 'take' => $take, 'unit_cost' => (string) $m->unit_cost];
            $left = bcsub($left, $take, self::S);
        }
        if (bccomp($left, '0', self::S) > 0) {
            $parts[] = ['source_type' => 'opening_balance', 'source_id' => null, 'source_line_id' => null, 'purchase_bill_id' => null,
                'supplier_id' => null, 'ref' => 'رصيد بلا مصدر', 'movement_id' => null, 'received_at' => $layer->received_at,
                'qty_in' => $left, 'take' => $left, 'unit_cost' => (string) $layer->unit_cost];
        }

        return array_reverse($parts);
    }

    private function source(object $m): array
    {
        $line = preg_match('/^doc:\d+:line:(\d+)/', (string) $m->idempotency_key, $x) ? (int) $x[1] : null;
        if ($m->source_type === StockDocument::SOURCE_TYPE && ($doc = StockDocument::find($m->source_id))) {
            if ($m->type === 'purchase') {
                $billItem = $line ? DB::table('stock_document_lines')->where('id', $line)->value('purchase_bill_item_id') : null;
                $bill = $doc->purchase_bill_id ? DB::table('purchase_bills')->where('id', $doc->purchase_bill_id)->value('bill_number') : null;

                return ['source_type' => $doc->purchase_bill_id ? 'purchase_invoice' : 'receipt', 'source_id' => $doc->purchase_bill_id ?? $doc->id,
                    'source_line_id' => $billItem ?? $line, 'purchase_bill_id' => $doc->purchase_bill_id, 'supplier_id' => $doc->supplier_id,
                    'ref' => $bill ?? $doc->number];
            }

            return ['source_type' => 'transfer_in', 'source_id' => $doc->id, 'source_line_id' => $line, 'purchase_bill_id' => null,
                'supplier_id' => null, 'ref' => $doc->number];
        }

        return ['source_type' => $m->type === 'adjustment' ? 'adjustment_in' : 'opening_balance', 'source_id' => $m->source_id,
            'source_line_id' => null, 'purchase_bill_id' => null, 'supplier_id' => null, 'ref' => $m->type === 'adjustment' ? 'زيادة جرد' : 'رصيد أول المدة'];
    }
}
