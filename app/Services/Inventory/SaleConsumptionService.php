<?php

namespace App\Services\Inventory;

use App\Models\InventorySetting;
use App\Models\Order;
use App\Models\Recipe;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StockSalePosting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * خصم المخزون عند البيع حسب الوصفات.
 *
 * عند دفع الطلب: كل سطر غير ملغى ← وصفة المنتج السارية يوم الدفع (المحاسبي) ← تفكيك الوصفات الفرعية إلى مواد
 * خام ← حركة "استهلاك بيع" لكل مادة من مخزن قسم السطر في فرع الطلب (وإلا مخزن البيع الافتراضي للفرع)،
 * بمتوسط التكلفة. هذه هي قواعد الاستهلاك النظري نفسها في التحليلات، فالخصم يطابق النظري تماماً.
 *
 * القاعدة الأهم: الخصم لا يوقف البيع أبداً.
 *  - يعمل بعد حفظ الدفع (OrderPaid يُطلق بعد الـ commit)، وأي خطأ يُسجَّل ولا يصل للكاشير (handlePaid لا يرمي).
 *  - الرصيد السالب مسموح لحركات البيع وحدها مهما كان إعداد الرصيد السالب؛ الجرد يصحّحه.
 *  - انتظار أقفال الأرصدة محدود بثوانٍ قليلة حتى لا تعلق شاشة الدفع خلف عملية أخرى.
 *  - الطلب الذي فشل خصمه يبقى "فشل" في stock_sale_postings ويُعاد من الإعدادات أو بأمر inventory:post-sales.
 * منع التكرار: مفتاح لكل (طلب، سطر، مادة) في الدفتر، وسجل واحد لكل طلب.
 */
class SaleConsumptionService
{
    public const SOURCE_TYPE = 'order';

    private const LOCK_WAIT_SECONDS = 5;

    /** @var array<string, array<int, string>|null> تفكيك الوصفة لكل "منتج|يوم" */
    private array $exploded = [];

    public function __construct(
        private StockLedger $ledger,
        private RecipeService $recipes,
        private ?AccountingDay $accountingDay = null,
    ) {
        $this->accountingDay ??= AccountingDay::fromConfig();
    }

    /** نقطة الدخول من حدث الدفع: لا ترمي استثناءً أبداً. */
    public function handlePaid(Order $order): void
    {
        try {
            if (! $this->appliesTo($order)) {
                return;
            }
            $this->post($order);
        } catch (Throwable $e) {
            $this->recordFailure($order, $e);
        }
    }

    /** هل الخصم مفعّل، والطلب دُفع بعد تشغيله؟ */
    public function appliesTo(Order $order): bool
    {
        $s = InventorySetting::current();
        if (! $s->deduct_sales || ! $s->deduct_sales_from || ! $order->paid_at) {
            return false;
        }

        return CarbonImmutable::parse($order->paid_at)->greaterThanOrEqualTo($s->deduct_sales_from);
    }

    /**
     * خصم طلب مدفوع. يعيد السجل كما هو إن خُصم أو عُكس سابقاً.
     *
     * @throws Throwable عند فشل التسجيل (handlePaid وretryPending يلتقطانه)
     */
    public function post(Order $order): StockSalePosting
    {
        $existing = StockSalePosting::where('order_id', $order->id)->first();
        if ($existing && $existing->status !== StockSalePosting::FAILED) {
            return $existing;
        }

        $paidAt = CarbonImmutable::parse($order->paid_at ?? now());
        $day = $this->accountingDay->of($paidAt);
        [$deptMap, $defaults] = SalesWarehouses::map();

        $items = DB::table('order_items')
            ->where('order_id', $order->id)
            ->where('status', '!=', 'cancelled')
            ->orderBy('id')
            ->get(['id', 'item_id', 'department_id', 'item_name', 'quantity']);

        $issues = [];
        $planned = [];
        foreach ($items as $oi) {
            $warehouseId = $deptMap[$order->branch_id][$oi->department_id] ?? $defaults[$order->branch_id] ?? null;
            if ($warehouseId === null) {
                $issues[] = ['order_item_id' => $oi->id, 'item_id' => $oi->item_id, 'name' => $oi->item_name, 'reason' => 'no_warehouse'];

                continue;
            }
            $perUnit = $this->perUnit((int) $oi->item_id, $day);
            if ($perUnit === null) {
                $issues[] = ['order_item_id' => $oi->id, 'item_id' => $oi->item_id, 'name' => $oi->item_name, 'reason' => 'no_recipe'];

                continue;
            }
            foreach ($perUnit as $stockItemId => $qty) {
                // استهلاك المادة = الكمية المباعة × كمية المادة لكل وحدة من المنتج
                $used = UnitConverter::round(bcmul((string) $oi->quantity, $qty, UnitConverter::SCALE + 6));
                if (bccomp($used, '0', UnitConverter::SCALE) <= 0) {
                    continue;
                }
                $planned[] = [$warehouseId, (int) $stockItemId, $used, (int) $oi->id];
            }
        }

        // المواد غير المخزّنة لا تُتتبع ولا تُسجَّل لها حركات.
        $stored = StockItem::whereIn('id', array_unique(array_column($planned, 1)))->where('is_stored', true)->pluck('id')->flip();
        $lines = [];
        foreach ($planned as [$warehouseId, $stockItemId, $used, $orderItemId]) {
            if (! isset($stored[$stockItemId])) {
                continue;
            }
            $lines[] = new StockMovementLine(
                warehouseId: $warehouseId,
                stockItemId: $stockItemId,
                type: 'sale',
                quantity: '-'.$used,
                occurredAt: $paidAt,
                sourceType: self::SOURCE_TYPE,
                sourceId: $order->id,
                idempotencyKey: "sale:{$order->id}:{$orderItemId}:{$stockItemId}",
                sourceLineId: $orderItemId,
                notes: $order->order_number,
            );
        }

        $movements = $lines === [] ? [] : $this->withShortLockWait(fn () => $this->ledger->postMany($lines, null, allowNegative: true));
        $total = '0';
        foreach ($movements as $m) {
            $total = bcsub($total, (string) $m->total_cost, 6);
        }

        $status = $movements === [] ? StockSalePosting::SKIPPED : ($issues === [] ? StockSalePosting::POSTED : StockSalePosting::PARTIAL);

        return StockSalePosting::updateOrCreate(['order_id' => $order->id], [
            'status' => $status,
            'movements_count' => count($movements),
            'total_cost' => $total,
            'issues' => $issues ?: null,
            'error' => null,
            'attempts' => ($existing?->attempts ?? 0) + 1,
            'posted_at' => now(),
        ]);
    }

    /** عكس خصم طلب (إلغاء أو استرجاع بعد الدفع): حركة عكسية لكل حركة بيع بتكلفتها الأصلية. لا ترمي. */
    public function reverse(Order $order, string $reason): void
    {
        try {
            $posting = StockSalePosting::where('order_id', $order->id)->first();
            if (! $posting || $posting->status === StockSalePosting::REVERSED) {
                return;
            }
            $movements = StockMovement::where('source_type', self::SOURCE_TYPE)->where('source_id', $order->id)
                ->where('type', 'sale')->whereNull('reversal_of_id')
                ->whereNotExists(fn ($q) => $q->from('stock_movements as r')->whereColumn('r.reversal_of_id', 'stock_movements.id'))
                ->orderBy('id')->get();
            $this->withShortLockWait(function () use ($movements, $reason) {
                foreach ($movements as $m) {
                    $this->ledger->reverse($m, $reason, null, allowNegative: true);
                }
            });
            $posting->update(['status' => StockSalePosting::REVERSED, 'reversed_at' => now()]);
        } catch (Throwable $e) {
            Log::error('inventory.sale_deduction.reverse_failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * إعادة المحاولة: الطلبات المدفوعة بعد التشغيل التي فشل خصمها أو لم يُسجَّل لها شيء (مثلاً انقطاع أثناء الدفع).
     *
     * @return array{posted:int, failed:int}
     */
    public function retryPending(int $limit = 500): array
    {
        $result = ['posted' => 0, 'failed' => 0];
        foreach ($this->pendingQuery(withFailed: true)->orderBy('paid_at')->limit($limit)->get() as $order) {
            try {
                $this->post($order);
                $result['posted']++;
            } catch (Throwable $e) {
                $this->recordFailure($order, $e);
                $result['failed']++;
            }
        }

        return $result;
    }

    /** @return array{failed:int, pending:int, partial:int} للعرض في الإعدادات */
    public function stats(): array
    {
        $counts = StockSalePosting::query()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return [
            'failed' => (int) ($counts[StockSalePosting::FAILED] ?? 0),
            'pending' => $this->pendingQuery(withFailed: false)->count(),
            'partial' => (int) ($counts[StockSalePosting::PARTIAL] ?? 0),
        ];
    }

    /** الطلبات المدفوعة بعد التشغيل بلا سجل خصم (ومع الفاشلة إن طُلب). */
    private function pendingQuery(bool $withFailed)
    {
        $s = InventorySetting::current();
        $q = Order::query()->withoutGlobalScopes()
            ->where('payment_status', Order::PAYMENT_STATUS_PAID)
            ->whereNull('deleted_at');
        if (! $s->deduct_sales || ! $s->deduct_sales_from) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where('paid_at', '>=', $s->deduct_sales_from)
            ->where(function ($w) use ($withFailed) {
                $w->whereNotExists(fn ($x) => $x->from('stock_sale_postings as p')->whereColumn('p.order_id', 'orders.id'));
                if ($withFailed) {
                    $w->orWhereExists(fn ($x) => $x->from('stock_sale_postings as p')->whereColumn('p.order_id', 'orders.id')->where('p.status', StockSalePosting::FAILED));
                }
            });
    }

    /** @return array<int, string>|null المواد بالوحدة الأساسية لكل وحدة من المنتج، أو null إن لا وصفة */
    private function perUnit(int $itemId, string $day): ?array
    {
        $key = "{$itemId}|{$day}";
        if (! array_key_exists($key, $this->exploded)) {
            $recipe = $this->recipes->versionAt($itemId, null, $day);
            $this->exploded[$key] = $recipe instanceof Recipe ? $this->recipes->explode($recipe, $day) : null;
        }

        return $this->exploded[$key];
    }

    private function recordFailure(Order $order, Throwable $e): void
    {
        try {
            Log::error('inventory.sale_deduction.failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            $row = StockSalePosting::firstOrNew(['order_id' => $order->id]);
            if ($row->exists && $row->status !== StockSalePosting::FAILED) {
                return;
            }
            $row->fill(['status' => StockSalePosting::FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000), 'attempts' => (int) $row->attempts + 1])->save();
        } catch (Throwable) {
            // حتى تسجيل الفشل لا يجوز أن يصل للكاشير؛ إعادة المحاولة تلتقط الطلب لأنه بلا سجل.
        }
    }

    /**
     * انتظار قفل سطر رصيد مشغول بثوانٍ قليلة بدل الافتراضي (50 ث في MySQL)، ثم إعادة القيمة الأصلية.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function withShortLockWait(callable $fn): mixed
    {
        if (DB::getDriverName() !== 'mysql' || DB::transactionLevel() > 0) {
            return $fn();
        }
        $previous = (int) DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS t')->t;
        DB::statement('SET SESSION innodb_lock_wait_timeout = '.self::LOCK_WAIT_SECONDS);
        try {
            return $fn();
        } finally {
            DB::statement('SET SESSION innodb_lock_wait_timeout = '.$previous);
        }
    }
}
