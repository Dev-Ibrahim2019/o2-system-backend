<?php
// app/Http/Controllers/Api/MenuController.php
// FIXED: Categories and Items now appear correctly for all cashiers

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Department;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MenuController extends ApiController
{
    // GET /menu — يُفلتر حسب الفرع (من query param أو من المستخدم المسجل)
    public function index(Request $request)
    {
        $authUser = auth()->user();
        $branchId = $request->query('branch_id') ?? $authUser?->branch_id;

        // تحويل بيانات القسم لتتوافق مع توقعات Frontend
        $formatCategory = function ($dept) {
            return [
                'id'      => $dept->id,
                'name'    => $dept->name,
                'name_ar' => $dept->nameAr ?? $dept->name,
                'icon'    => $dept->icon ?? '🍽️',
                'color'   => $dept->color ?? '#ef4444',
                'type'    => $dept->type,
                'items'   => $dept->items->map(function ($item) {
                    return [
                        'id'            => $item->id,
                        'name'          => $item->name,
                        'name_ar'       => $item->name_ar ?? $item->name,
                        'code'          => $item->code,
                        'image'         => $item->image,
                        'image_url'     => $item->image_url,
                        'unit'          => $item->unit,
                        'price'         => (float) ($item->price ?? 0),
                        'department_id' => $item->department_id,
                        'is_active'     => $item->is_active ?? true,
                        'is_availble'   => true,
                    ];
                })->values()->all(),
            ];
        };

        Log::info('POS MENU TRACE START', [
            'branch_id' => $branchId,
            'is_super_admin' => $authUser?->hasRole('super-admin'),
        ]);

        // ── لو super-admin أو مفيش branchId: نجيب كل الأقسام وكل الأصناف النشطة ──
        if (($authUser && $authUser->hasRole('super-admin')) || !$branchId) {
            $departments = Department::with(['items' => function ($query) {
                $query->where('is_active', true);
            }])->whereHas('items', fn($q) => $q->where('is_active', true))
                ->orDoesntHave('items')
                ->get();

            // تعيين السعر من أول branch_item متاح لكل صنف
            foreach ($departments as $department) {
                foreach ($department->items as $item) {
                    $price = DB::table('branch_item')
                        ->where('item_id', $item->id)
                        ->where('is_active', true)
                        ->value('price');
                    $item->price = $price !== null ? (float) $price : 0;
                }
            }

            Log::info('POS MENU TRACE super-admin', [
                'departments_count' => $departments->count(),
                'departments' => $departments->map(fn($d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'items_count' => $d->items->count(),
                    'items' => $d->items->map(fn($i) => ['id' => $i->id, 'price' => $i->price]),
                ]),
            ]);

            return response()->json([
                'data' => [
                    'categories' => $departments->map($formatCategory)->values()->all()
                ]
            ]);
        }

        // ── للكاشير العادي ──
        // الخطوة 1: نجيب كل الأقسام (أي قسم عنده أصناف نشطة في أي فرع)
        // الخطوة 2: نجيب بس الأصناف المرتبطة بالفرع ده (via branch_item.is_active = true)
        $departments = Department::whereHas('items.branches', function ($q) use ($branchId) {
            $q->where('branch_item.branch_id', $branchId)
                ->where('branch_item.is_active', true);
        })
            ->with(['items' => function ($query) use ($branchId) {
                // نجيب الأصناف اللي is_active = true ولها سعر في هذا الفرع
                $query->where('items.is_active', true)
                    ->whereHas('branches', function ($q) use ($branchId) {
                        $q->where('branch_item.branch_id', $branchId)
                            ->where('branch_item.is_active', true);
                    });
            }])
            ->get();

        // ── تعيين السعر من pivot لكل صنف ──
        foreach ($departments as $department) {
            foreach ($department->items as $item) {
                $price = DB::table('branch_item')
                    ->where('branch_id', $branchId)
                    ->where('item_id', $item->id)
                    ->value('price');
                $item->price = $price !== null ? (float) $price : 0;
            }
        }

        Log::info('POS MENU TRACE cashier', [
            'branch_id' => $branchId,
            'departments_count' => $departments->count(),
            'departments' => $departments->map(fn($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'items_count' => $d->items->count(),
                'items' => $d->items->pluck('id'),
            ]),
        ]);

        return response()->json([
            'data' => [
                'categories' => $departments->map($formatCategory)->values()->all()
            ]
        ]);
    }
}
