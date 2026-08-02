<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DiningZone;
use App\Models\DiningTable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DiningZoneController extends Controller
{
    // 1. عرض جميع القاعات مع طاولاتها
    public function index(Request $request)
    {
        $user = $request->user();

        $query = DiningZone::with(['tables' => function ($q) {
            $q->orderByRaw("CAST(SUBSTRING(table_number FROM '[0-9]+$') AS INTEGER)");
        }]);

        // إذا لم يكن super-admin أو admin، فلتر حسب الفرع
        if (!$user->hasRole('super-admin') && !$user->hasRole('admin')) {
            $query->where('branch_id', $user->branch_id);
        }

        $zones = $query->get();

        return response()->json(['success' => true, 'data' => $zones]);
    }

    // 2. عرض قاعة معينة
    public function show($id)
    {
        $zone = DiningZone::with(['tables' => function ($q) {
            $q->orderByRaw("CAST(SUBSTRING(table_number FROM '[0-9]+$') AS INTEGER)");
        }])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $zone]);
    }

    // 3. إنشاء قاعة جديدة مع الطاولات
    public function store(Request $request)
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:10',
            'tables_count' => 'required|integer|min:1|max:200',
            'tables_capacity' => 'required|integer|min:1|max:20',
        ]);

        $user = $request->user();

        // إذا لم يكن super-admin أو admin، تحقق من الفرع
        if (!$user->hasRole('super-admin') && !$user->hasRole('admin') && $user->branch_id != $request->branch_id) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكنك إنشاء قاعة لفرع آخر!'
            ], 403);
        }

        // تحقق من عدم تكرار الكود في نفس الفرع
        $exists = DiningZone::where('branch_id', $request->branch_id)
            ->where('code', strtoupper($request->code))
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'كود القاعة موجود مسبقاً في هذا الفرع!'
            ], 422);
        }

        // إنشاء القاعة
        $zone = DiningZone::create([
            'branch_id' => $request->branch_id,
            'name' => $request->name,
            'code' => strtoupper($request->code),
            'status' => 'ACTIVE',
        ]);

        // إنشاء الطاولات تلقائياً
        $tables = [];
        $zoneCode = strtoupper($request->code);

        for ($i = 1; $i <= $request->tables_count; $i++) {
            $tableNumber = $zoneCode . $i;
            $qrCode = strtoupper(Str::random(8));
            $qrUrl = url("/customer/{$qrCode}");

            $tables[] = DiningTable::create([
                'dining_zone_id' => $zone->id,
                'branch_id' => $zone->branch_id,
                'code' => $tableNumber,
                'table_number' => $tableNumber,
                'qr_code' => $qrCode,
                'qr_url' => $qrUrl,
                'capacity' => $request->tables_capacity,
                'status' => 'AVAILABLE',
            ]);
        }

        $zone->load('tables');

        return response()->json([
            'success' => true,
            'message' => "تم إنشاء القاعة '{$zone->name}' مع " . count($tables) . " طاولة بنجاح",
            'data' => $zone,
        ], 201);
    }

    // 4. تحديث القاعة
    public function update(Request $request, $id)
    {
        $zone = DiningZone::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|in:ACTIVE,INACTIVE',
        ]);

        $zone->update($request->only(['name', 'status']));

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث القاعة بنجاح',
            'data' => $zone,
        ]);
    }

    // 5. حذف القاعة وطاولاتها
    public function destroy($id)
    {
        $zone = DiningZone::findOrFail($id);
        $zone->tables()->delete();
        $zone->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف القاعة وطاولاتها بنجاح',
        ]);
    }

    // 6. إضافة طاولة يدوياً لقاعة
    public function addTable(Request $request, $zoneId)
    {
        $zone = DiningZone::findOrFail($zoneId);

        $request->validate([
            'capacity' => 'required|integer|min:1|max:20',
        ]);

        $lastTable = DiningTable::where('dining_zone_id', $zoneId)
            ->orderBy('id', 'desc')
            ->first();

        $nextNumber = 1;
        if ($lastTable) {
            $nextNumber = (int) substr($lastTable->table_number, strlen($zone->code)) + 1;
        }

        $tableNumber = $zone->code . $nextNumber;
        $qrCode = strtoupper(Str::random(8));
        $qrUrl = url("/customer/{$qrCode}");

        $table = DiningTable::create([
            'dining_zone_id' => $zoneId,
            'branch_id' => $zone->branch_id,
            'code' => $tableNumber,
            'table_number' => $tableNumber,
            'qr_code' => $qrCode,
            'qr_url' => $qrUrl,
            'capacity' => $request->capacity,
            'status' => 'AVAILABLE',
        ]);

        return response()->json([
            'success' => true,
            'message' => "تم إضافة الطاولة {$tableNumber} بنجاح",
            'data' => $table,
        ], 201);
    }

    // 7. حذف طاولة
    public function destroyTable($zoneId, $tableId)
    {
        $table = DiningTable::where('dining_zone_id', $zoneId)->findOrFail($tableId);
        $table->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف الطاولة بنجاح',
        ]);
    }

    // 8. تحديث حالة الطاولة
    public function updateTableStatus(Request $request, $zoneId, $tableId)
    {
        $table = DiningTable::where('dining_zone_id', $zoneId)->findOrFail($tableId);

        $request->validate([
            'status' => 'required|in:AVAILABLE,OCCUPIED,PAYMENT_PENDING,PAID,RESERVED,CLEANING,MERGED',
        ]);

        $table->update(['status' => $request->status]);

        return response()->json([
            'success' => true,
            'data' => $table,
        ]);
    }
}
