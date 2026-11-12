<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\Department;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * تقارير الأقسام: مبيعات كل قسم في فرع، واستهلاكه النظري وتكلفته (صفحة /inventory/departments).
 *
 * "القسم" هنا = قسم × فرع (مخزن القسم وجرده مرتبطان بالفرع).
 *
 * التعريفات:
 *  - المبيعات = Σ قيمة أسطر الطلبات (السعر × الكمية) للطلبات المدفوعة غير الملغاة وغير المحذوفة، والأسطر غير
 *    الملغاة، بيوم الدفع المحاسبي. قسم السطر هو قسم الصنف لحظة البيع (order_items.department_id).
 *    خصم الطلب الكلي ورسوم التوصيل على الطلب لا على الأسطر، فلا تدخل.
 *  - الاستهلاك النظري = حركات "استهلاك بيع" في الكاردكس (وعكسها يصافيها)، بيوم الحركة المحاسبي، فتطابق الأرقام
 *    كرت الصنف. التكلفة = تكلفة الحركة نفسها (متوسط التكلفة لحظة البيع).
 *    الحركة تُنسب لسطر الطلب من مفتاح عدم تكرارها sale:{طلب}:{سطر}:{مادة} (SaleConsumptionService)، والعكس
 *    يأخذ مفتاح حركته الأصلية. لذلك تُنسب للقسم حتى لو خُصمت من مخزن البيع الافتراضي للفرع.
 *  - الطلبات بلا خصم = مدفوعة في الفترة وليس لها ترحيل خصم ناجح (قبل تفعيل الخصم أو فشل) — تُعرض تنبيهاً، ولا
 *    يُحسب لها استهلاك تقديري.
 */
class DepartmentReportService
{
    private const S = 6;

    private AccountingDay $day;

    public function __construct(private WarehouseAccess $access)
    {
        $this->day = AccountingDay::fromConfig();
    }

    // ── الفترة ────────────────────────────────────────────────────────────────

    /** @return array{from:string, to:string, days:int} الافتراضي: من أول الشهر الحالي حتى اليوم المحاسبي الحالي. */
    public function period(?string $from, ?string $to): array
    {
        $today = $this->day->of(now());
        $from = $from ?: CarbonImmutable::parse($today)->startOfMonth()->toDateString();
        $to = $to ?: $today;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return ['from' => $from, 'to' => $to, 'days' => (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1];
    }

    // ── القسم في الفرع ───────────────────────────────────────────────────────

    /** مخزن القسم في الفرع (إن رُبط)، وإلا null — مبيعاته تُخصم حينها من مخزن البيع الافتراضي للفرع. */
    public function warehouseOf(int $departmentId, int $branchId): ?Warehouse
    {
        return Warehouse::where('type', Warehouse::TYPE_STANDARD)
            ->where('branch_id', $branchId)->where('department_id', $departmentId)->first();
    }

    /** هل يرى المستخدم هذا القسم: صلاحية على مخزنه، أو وصول شامل للأقسام بلا مخزن. */
    public function canView(User $user, ?Warehouse $warehouse): bool
    {
        return $warehouse ? $this->access->canAccess($user, $warehouse) : $this->access->hasGlobalAccess($user);
    }

    /** @return array<string, mixed> رأس صفحة القسم: الاسم والفرع والمخزن والفروع الأخرى للقسم نفسه. */
    public function unit(Department $department, Branch $branch, ?Warehouse $warehouse, User $user): array
    {
        $global = $this->access->hasGlobalAccess($user);
        $assigned = $global ? [] : $this->access->assignedIds($user);
        $warehouses = Warehouse::where('type', Warehouse::TYPE_STANDARD)->where('department_id', $department->id)->pluck('id', 'branch_id');
        $branchIds = DB::table('branch_department')->where('department_id', $department->id)->pluck('branch_id')
            ->merge($warehouses->keys())->push($branch->id)->map(fn ($id) => (int) $id)->unique()->all();

        return [
            'department' => $this->departmentInfo($department),
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'warehouse' => $warehouse ? ['id' => $warehouse->id, 'name' => $warehouse->name, 'code' => $warehouse->code] : null,
            'can_edit_warehouse' => $warehouse ? $this->access->canEdit($user, $warehouse) : false,
            'branches' => Branch::whereIn('id', $branchIds)->orderBy('id')->get(['id', 'name'])
                ->filter(fn ($b) => $global || in_array((int) ($warehouses[$b->id] ?? 0), $assigned, true))
                ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values()->all(),
        ];
    }

    // ── 1. قائمة الأقسام ─────────────────────────────────────────────────────

    /**
     * سطر لكل قسم × فرع: أقسام الفروع المربوطة، والأقسام التي لها مخزن، وكل قسم بيعت أصنافه في الفترة.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function summary(User $user, array $p, ?int $branchId): array
    {
        $key = fn ($r) => "{$r->department_id}-{$r->branch_id}";
        $sales = $this->salesQuery($p, $branchId)
            ->groupBy('oi.department_id', 'o.branch_id')
            ->selectRaw('oi.department_id, o.branch_id, SUM(oi.total) as sales, COUNT(DISTINCT o.id) as orders')
            ->get()->keyBy($key);

        $costs = $this->consumptionQuery($p, $branchId)
            ->groupBy('oi.department_id', 'o.branch_id')
            ->selectRaw('oi.department_id, o.branch_id, -SUM(m.total_cost) as cost')
            ->get()->keyBy($key);

        $undeducted = $this->undeductedQuery($p, $branchId)
            ->groupBy('oi.department_id', 'o.branch_id')
            ->selectRaw('oi.department_id, o.branch_id, COUNT(DISTINCT o.id) as c')
            ->get()->keyBy($key);

        $itemCounts = DB::table('branch_item as bi')->join('items as i', 'i.id', '=', 'bi.item_id')
            ->when($branchId, fn ($q) => $q->where('bi.branch_id', $branchId))
            ->groupBy('i.department_id', 'bi.branch_id')
            ->selectRaw('i.department_id, bi.branch_id, COUNT(*) as c')
            ->get()->keyBy($key);

        $warehouses = Warehouse::where('type', Warehouse::TYPE_STANDARD)->whereNotNull('department_id')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['id', 'name', 'code', 'branch_id', 'department_id'])
            ->keyBy($key);

        $pairs = DB::table('branch_department')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['department_id', 'branch_id'])->map($key)
            ->merge($warehouses->keys())->merge($sales->keys())->merge($costs->keys())->unique()->values();

        $global = $this->access->hasGlobalAccess($user);
        $assigned = $global ? [] : $this->access->assignedIds($user);
        $split = fn (int $i) => $pairs->map(fn ($k) => (int) explode('-', $k)[$i])->unique()->all();
        $departments = Department::withTrashed()->whereIn('id', $split(0))->get()->keyBy('id');
        $branches = Branch::whereIn('id', $split(1))->get(['id', 'name'])->keyBy('id');

        $rows = [];
        foreach ($pairs as $k) {
            [$d, $b] = array_map('intval', explode('-', $k));
            $dept = $departments[$d] ?? null;
            $w = $warehouses[$k] ?? null;
            if (! $dept || ! isset($branches[$b]) || ! ($global || ($w && in_array($w->id, $assigned, true)))) {
                continue;
            }
            // قسم محذوف أو موقوف بلا مبيعات ولا استهلاك في الفترة لا يُعرض.
            if (! isset($sales[$k]) && ! isset($costs[$k]) && ($dept->trashed() || ! $dept->is_active)) {
                continue;
            }
            $s = (string) ($sales[$k]->sales ?? '0');
            $c = (string) ($costs[$k]->cost ?? '0');
            $rows[] = [
                'key' => $k,
                'department' => $this->departmentInfo($dept),
                'branch' => ['id' => $b, 'name' => $branches[$b]->name],
                'warehouse' => $w ? ['id' => $w->id, 'name' => $w->name, 'code' => $w->code] : null,
                'sale_items_count' => (int) ($itemCounts[$k]->c ?? 0),
                'orders' => (int) ($sales[$k]->orders ?? 0),
                'sales' => $this->fmt($s),
                'cost' => $this->fmt($c),
                'profit' => $this->fmt(bcsub($s, $c, self::S)),
                'food_cost_percent' => $this->percent($c, $s),
                'undeducted_orders' => (int) ($undeducted[$k]->c ?? 0),
            ];
        }
        usort($rows, fn ($a, $b) => [(float) $b['sales'], $a['department']['name']] <=> [(float) $a['sales'], $b['department']['name']]);

        $totalSales = array_reduce($rows, fn ($t, $r) => bcadd($t, $r['sales'], self::S), '0');
        $totalCost = array_reduce($rows, fn ($t, $r) => bcadd($t, $r['cost'], self::S), '0');
        // الطلب الواحد قد يضم أصنافاً من عدة أقسام: الإجماليات تعدّ الطلبات المميزة لا مجموع الأقسام.
        $keys = array_column($rows, 'key');
        $pairSql = "CONCAT(oi.department_id, '-', o.branch_id)";
        $distinct = fn (Builder $q) => $keys === [] ? 0
            : (int) $q->whereIn(DB::raw($pairSql), $keys)->selectRaw('COUNT(DISTINCT o.id) as c')->value('c');

        return [
            'rows' => $rows,
            'totals' => [
                'departments' => count($rows),
                'orders' => $distinct($this->salesQuery($p, $branchId)),
                'sales' => $this->fmt($totalSales),
                'cost' => $this->fmt($totalCost),
                'profit' => $this->fmt(bcsub($totalSales, $totalCost, self::S)),
                'food_cost_percent' => $this->percent($totalCost, $totalSales),
                'undeducted_orders' => $distinct($this->undeductedQuery($p, $branchId)),
            ],
        ];
    }

    // ── 2. أصناف البيع والاستهلاك ────────────────────────────────────────────

    /**
     * كل أصناف البيع التابعة للقسم (في قائمة الفرع أو المبيعة في الفترة)، مع المواد التي استهلكها كل صنف.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>, undeducted_orders: int}
     */
    public function saleItems(int $departmentId, int $branchId, array $p): array
    {
        $sales = $this->salesQuery($p, $branchId)->where('oi.department_id', $departmentId)
            ->groupBy('oi.item_id')
            ->selectRaw('oi.item_id, SUM(oi.quantity) as qty, SUM(oi.total) as sales, COUNT(DISTINCT o.id) as orders')
            ->get()->keyBy('item_id');

        $materials = $this->consumptionQuery($p, $branchId)->where('oi.department_id', $departmentId)
            ->join('stock_items as si', 'si.id', '=', 'm.stock_item_id')
            ->join('stock_units as u', 'u.id', '=', 'si.base_unit_id')
            ->groupBy('oi.item_id', 'm.stock_item_id', 'si.code', 'si.name', 'u.name')
            ->selectRaw('oi.item_id, m.stock_item_id, si.code, si.name, u.name as base_unit,
                -SUM(m.quantity) as qty, -SUM(m.total_cost) as cost')
            ->orderByDesc('cost')
            ->get()->groupBy('item_id');

        $itemIds = DB::table('branch_item as bi')->join('items as i', 'i.id', '=', 'bi.item_id')
            ->where('bi.branch_id', $branchId)->where('i.department_id', $departmentId)->pluck('i.id')
            ->merge($sales->keys())->merge($materials->keys())->map(fn ($id) => (int) $id)->unique()->values();
        $items = DB::table('items')->whereIn('id', $itemIds)->get(['id', 'code', 'name', 'name_ar', 'is_active'])->keyBy('id');
        $withRecipe = DB::table('recipes')->whereIn('item_id', $itemIds)->where('effective_from', '<=', $p['to'])
            ->distinct()->pluck('item_id')->map(fn ($id) => (int) $id)->flip();

        $totalSales = $sales->reduce(fn ($t, $r) => bcadd($t, (string) $r->sales, self::S), '0');
        $rows = [];
        $totalCost = '0';
        foreach ($itemIds as $id) {
            $item = $items[$id] ?? null;
            if (! $item) {
                continue;
            }
            $s = (string) ($sales[$id]->sales ?? '0');
            $mats = $materials[$id] ?? collect();
            $c = $mats->reduce(fn ($t, $m) => bcadd($t, (string) $m->cost, self::S), '0');
            $totalCost = bcadd($totalCost, $c, self::S);
            $rows[] = [
                'item_id' => $id,
                'code' => $item->code,
                'name' => $item->name_ar ?: $item->name,
                'is_active' => (bool) $item->is_active,
                'has_recipe' => isset($withRecipe[$id]),
                'quantity' => $this->fmt((string) ($sales[$id]->qty ?? '0')),
                'orders' => (int) ($sales[$id]->orders ?? 0),
                'sales' => $this->fmt($s),
                'cost' => $this->fmt($c),
                'profit' => $this->fmt(bcsub($s, $c, self::S)),
                'food_cost_percent' => $this->percent($c, $s),
                'share_percent' => $this->percent($s, $totalSales),
                'materials' => $mats->map(fn ($m) => [
                    'stock_item_id' => (int) $m->stock_item_id,
                    'code' => $m->code,
                    'name' => $m->name,
                    'base_unit' => $m->base_unit,
                    'quantity' => $this->fmt((string) $m->qty),
                    'cost' => $this->fmt((string) $m->cost),
                ])->values()->all(),
            ];
        }
        usort($rows, fn ($a, $b) => [(float) $b['sales'], $a['name']] <=> [(float) $a['sales'], $b['name']]);

        return [
            'rows' => $rows,
            'totals' => [
                'quantity' => $this->fmt($sales->reduce(fn ($t, $r) => bcadd($t, (string) $r->qty, self::S), '0')),
                'sales' => $this->fmt($totalSales),
                'cost' => $this->fmt($totalCost),
                'profit' => $this->fmt(bcsub($totalSales, $totalCost, self::S)),
                'food_cost_percent' => $this->percent($totalCost, $totalSales),
            ],
            'undeducted_orders' => $this->undeductedCount($departmentId, $branchId, $p),
        ];
    }

    /**
     * تجميع على مستوى المادة الخام: الاستهلاك النظري للقسم وقيمته، وأصناف البيع التي ساهمت فيه.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>, undeducted_orders: int}
     */
    public function consumption(int $departmentId, int $branchId, array $p): array
    {
        $lines = $this->consumptionQuery($p, $branchId)->where('oi.department_id', $departmentId)
            ->join('stock_items as si', 'si.id', '=', 'm.stock_item_id')
            ->join('stock_units as u', 'u.id', '=', 'si.base_unit_id')
            ->leftJoin('items as i', 'i.id', '=', 'oi.item_id')
            ->groupBy('m.stock_item_id', 'si.code', 'si.name', 'u.name', 'oi.item_id', 'i.name', 'i.name_ar', 'i.code')
            ->selectRaw('m.stock_item_id, si.code, si.name, u.name as base_unit,
                oi.item_id, COALESCE(i.name_ar, i.name) as item_name, i.code as item_code,
                -SUM(m.quantity) as qty, -SUM(m.total_cost) as cost')
            ->get()->groupBy('stock_item_id');

        $rows = [];
        $totalCost = '0';
        foreach ($lines as $stockItemId => $group) {
            $first = $group->first();
            $qty = $group->reduce(fn ($t, $r) => bcadd($t, (string) $r->qty, self::S), '0');
            $cost = $group->reduce(fn ($t, $r) => bcadd($t, (string) $r->cost, self::S), '0');
            $totalCost = bcadd($totalCost, $cost, self::S);
            $rows[] = [
                'stock_item_id' => (int) $stockItemId,
                'code' => $first->code,
                'name' => $first->name,
                'base_unit' => $first->base_unit,
                'quantity' => $this->fmt($qty),
                'cost' => $this->fmt($cost),
                'unit_cost' => bccomp($qty, '0', self::S) === 0 ? null : $this->fmt(bcdiv($cost, $qty, self::S)),
                'items' => $group->sortByDesc(fn ($r) => (float) $r->qty)->map(fn ($r) => [
                    'item_id' => (int) $r->item_id,
                    'code' => $r->item_code,
                    'name' => $r->item_name,
                    'quantity' => $this->fmt((string) $r->qty),
                    'cost' => $this->fmt((string) $r->cost),
                    'share_percent' => $this->percent((string) $r->qty, $qty),
                ])->values()->all(),
            ];
        }
        foreach ($rows as &$row) {
            $row['cost_share_percent'] = $this->percent($row['cost'], $totalCost);
        }
        unset($row);
        usort($rows, fn ($a, $b) => [(float) $b['cost'], $a['name']] <=> [(float) $a['cost'], $b['name']]);

        return [
            'rows' => $rows,
            'totals' => ['cost' => $this->fmt($totalCost), 'materials' => count($rows)],
            'undeducted_orders' => $this->undeductedCount($departmentId, $branchId, $p),
        ];
    }

    // ── 3. الإحصائيات ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function stats(int $departmentId, int $branchId, array $p): array
    {
        $base = fn () => $this->salesQuery($p, $branchId)->where('oi.department_id', $departmentId);
        $head = $base()->selectRaw('COALESCE(SUM(oi.total), 0) as sales, COUNT(DISTINCT o.id) as orders')->first();
        $sales = (string) $head->sales;
        $orders = (int) $head->orders;
        $cost = (string) ($this->consumptionQuery($p, $branchId)->where('oi.department_id', $departmentId)
            ->selectRaw('COALESCE(-SUM(m.total_cost), 0) as cost')->value('cost') ?? '0');

        // أعلى وأقل الأصناف مبيعاً (بالكمية) — من الأصناف التي بيعت فعلاً في الفترة.
        $byItem = $base()->leftJoin('items as i', 'i.id', '=', 'oi.item_id')
            ->groupBy('oi.item_id', 'i.name', 'i.name_ar', 'i.code')
            ->selectRaw('oi.item_id, COALESCE(i.name_ar, i.name) as name, i.code, SUM(oi.quantity) as qty, SUM(oi.total) as sales')
            ->get()
            ->map(fn ($r) => ['item_id' => (int) $r->item_id, 'code' => $r->code, 'name' => $r->name,
                'quantity' => $this->fmt((string) $r->qty), 'sales' => $this->fmt((string) $r->sales)]);
        $desc = $byItem->sort(fn ($a, $b) => [(float) $b['quantity'], (float) $b['sales']] <=> [(float) $a['quantity'], (float) $a['sales']])->values();
        $asc = $byItem->sort(fn ($a, $b) => [(float) $a['quantity'], (float) $a['sales']] <=> [(float) $b['quantity'], (float) $b['sales']])->values();

        // يومي: المبيعات مجمّعة بالساعة (UTC) ثم تُنسب لليوم المحاسبي — اليوم المحاسبي يبدأ على رأس ساعة.
        $hour = "DATE_FORMAT(o.paid_at, '%Y-%m-%d %H:00:00')";
        $daily = [];
        for ($d = CarbonImmutable::parse($p['from']); $d->toDateString() <= $p['to']; $d = $d->addDay()) {
            $daily[$d->toDateString()] = ['day' => $d->toDateString(), 'sales' => '0', 'cost' => '0', 'orders' => 0];
        }
        $byHour = $base()->groupByRaw($hour)->selectRaw("{$hour} as h, SUM(oi.total) as sales")->get();
        foreach ($byHour as $r) {
            $day = $this->day->of(CarbonImmutable::parse($r->h, 'UTC'));
            if (isset($daily[$day])) {
                $daily[$day]['sales'] = bcadd($daily[$day]['sales'], (string) $r->sales, self::S);
            }
        }
        // عدد الطلبات اليومي: كل طلب يُعدّ في يوم دفعه مرة واحدة.
        $orderDays = $base()->groupBy('o.id', 'o.paid_at')->select('o.id', 'o.paid_at')->get();
        foreach ($orderDays as $r) {
            $day = $this->day->of(CarbonImmutable::parse($r->paid_at, 'UTC'));
            if (isset($daily[$day])) {
                $daily[$day]['orders']++;
            }
        }
        $costByDay = $this->consumptionQuery($p, $branchId)->where('oi.department_id', $departmentId)
            ->groupBy('m.accounting_day')->selectRaw('m.accounting_day as d, -SUM(m.total_cost) as cost')->get();
        foreach ($costByDay as $r) {
            $day = CarbonImmutable::parse($r->d)->toDateString();
            if (isset($daily[$day])) {
                $daily[$day]['cost'] = (string) $r->cost;
            }
        }

        return [
            'totals' => [
                'sales' => $this->fmt($sales),
                'orders' => $orders,
                'avg_order' => $orders > 0 ? $this->fmt(bcdiv($sales, (string) $orders, self::S)) : '0',
                'cost' => $this->fmt($cost),
                'profit' => $this->fmt(bcsub($sales, $cost, self::S)),
                'food_cost_percent' => $this->percent($cost, $sales),
                'items_sold' => $byItem->count(),
            ],
            'top' => $desc->take(5)->values()->all(),
            // "الأقل مبيعاً" له معنى فقط إن زادت الأصناف عن خمسة (وإلا فهي القائمة نفسها).
            'bottom' => $byItem->count() > 5 ? $asc->take(5)->values()->all() : [],
            'daily' => array_map(fn ($r) => [...$r, 'sales' => $this->fmt($r['sales']), 'cost' => $this->fmt($r['cost'])], array_values($daily)),
            'undeducted_orders' => $this->undeductedCount($departmentId, $branchId, $p),
        ];
    }

    // ── الاستعلامات الأساسية ─────────────────────────────────────────────────

    /** أسطر المبيعات المحتسبة في الفترة (o = orders، oi = order_items). */
    public function salesQuery(array $p, ?int $branchId): Builder
    {
        [$start] = $this->day->bounds($p['from']);
        [, $end] = $this->day->bounds($p['to']);

        return DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.payment_status', 'paid')
            ->whereNull('o.deleted_at')
            ->where('o.status', '!=', 'cancelled')
            ->where('oi.status', '!=', 'cancelled')
            ->where('o.paid_at', '>=', $start->toDateTimeString())
            ->where('o.paid_at', '<', $end->toDateTimeString())
            ->when($branchId, fn ($q) => $q->where('o.branch_id', $branchId));
    }

    /**
     * حركات "استهلاك بيع" (وعكسها) في الفترة منسوبة لسطر الطلب (m = الحركة، oi = سطر الطلب، o = الطلب).
     * رقم السطر من مفتاح عدم التكرار sale:{طلب}:{سطر}:{مادة}؛ حركة العكس مفتاحها فارغ فتأخذ مفتاح أصلها.
     */
    public function consumptionQuery(array $p, ?int $branchId): Builder
    {
        return DB::table('stock_movements as m')
            ->leftJoin('stock_movements as orig', 'orig.id', '=', DB::raw('COALESCE(m.reversal_of_id, m.adjusts_movement_id)'))
            ->join('order_items as oi', 'oi.id', '=', self::orderItemIdSql())
            ->join('orders as o', 'o.id', '=', 'm.source_id')
            ->where('m.type', 'sale')
            ->where('m.source_type', SaleConsumptionService::SOURCE_TYPE)
            ->whereBetween('m.accounting_day', [$p['from'], $p['to']])
            ->when($branchId, fn ($q) => $q->where('o.branch_id', $branchId));
    }

    /** رقم سطر الطلب من مفتاح حركة البيع (أو مفتاح الحركة الأصلية للعكس). */
    public static function orderItemIdSql(): Expression
    {
        return DB::raw("CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(COALESCE(orig.idempotency_key, m.idempotency_key), ':', 3), ':', -1) AS UNSIGNED)");
    }

    /** أسطر طلبات محتسبة في المبيعات بلا ترحيل خصم ناجح (لا سجل، أو فشل). */
    private function undeductedQuery(array $p, ?int $branchId): Builder
    {
        return $this->salesQuery($p, $branchId)
            ->leftJoin('stock_sale_postings as sp', 'sp.order_id', '=', 'o.id')
            ->where(fn ($w) => $w->whereNull('sp.id')->orWhere('sp.status', 'failed'));
    }

    private function undeductedCount(int $departmentId, int $branchId, array $p): int
    {
        return (int) $this->undeductedQuery($p, $branchId)->where('oi.department_id', $departmentId)
            ->selectRaw('COUNT(DISTINCT o.id) as c')->value('c');
    }

    // ── أدوات ────────────────────────────────────────────────────────────────

    /** @return array{id:int, name:string, code:?string} */
    private function departmentInfo(Department $d): array
    {
        return ['id' => $d->id, 'name' => $d->nameAr ?: $d->name, 'code' => $d->code];
    }

    /** نسبة مئوية بخانتين، أو null إن كان المقام صفراً. */
    private function percent(string $part, string $whole): ?string
    {
        if (bccomp($whole, '0', self::S) === 0) {
            return null;
        }

        return number_format((float) bcdiv(bcmul($part, '100', self::S), $whole, self::S), 2, '.', '');
    }

    /** قيمة بست خانات كحد أقصى بلا أصفار زائدة. */
    private function fmt(string $v): string
    {
        $v = bcadd($v, '0', self::S);
        if (str_contains($v, '.')) {
            $v = rtrim(rtrim($v, '0'), '.');
        }

        return $v === '-0' ? '0' : $v;
    }
}
