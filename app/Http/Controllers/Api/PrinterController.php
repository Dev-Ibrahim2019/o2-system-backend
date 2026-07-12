<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintRoute;
use App\Services\Printing\PrinterService;
use App\Services\Printing\OrderPrintingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PrinterController extends Controller
{
    /**
     * جلب جميع الطابعات الخاصة بالفرع الحالي
     */
    public function index(Request $request)
    {
        $branchId = $this->resolveBranchId($request);

        $query = Printer::orderByDesc('is_active')->orderBy('name');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $printers = $query->get();

        return response()->json([
            'success' => true,
            'data'    => $printers,
        ]);
    }

    /**
     * إضافة طابعة جديدة
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'ip_address' => 'required|string|max:45',
            'port'       => 'nullable|string|max:10',
            'type'       => 'required|in:CASHIER,KITCHEN,BAR,OTHER',
            'branch_id'  => 'nullable|integer|exists:branches,id',
        ]);

        $branchId = $validated['branch_id'] ?? $this->resolveBranchId($request);

        if ($branchId === null) {
            return response()->json([
                'success' => false,
                'message' => 'branch_id مطلوب. يرجى تحديد الفرع أو التأكد من أن المستخدم مرتبط بفرع.',
            ], 400);
        }

        // فحص عدم التكرار
        $exists = Printer::where('branch_id', $branchId)
            ->where('ip_address', $validated['ip_address'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'ip_address' => 'طابعة بهذا العنوان موجودة مسبقاً في هذا الفرع',
            ]);
        }

        $printer = Printer::create([
            'name'       => $validated['name'],
            'ip_address' => $validated['ip_address'],
            'port'       => $validated['port'] ?? '9100',
            'type'       => $validated['type'],
            'branch_id'  => $branchId,
            'is_active'  => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تمت إضافة الطابعة بنجاح',
            'data'    => $printer,
        ], 201);
    }

    /**
     * جلب طابعة بالمعرّف
     */
    public function show(Request $request, $id)
    {
        $branchId = $this->resolveBranchId($request);

        $printer = Printer::where('branch_id', $branchId)
            ->with(['routes' => function ($q) {
                $q->with(['user', 'category', 'item']);
            }])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $printer,
        ]);
    }

    /**
     * تحديث طابعة
     */
    public function update(Request $request, $id)
    {
        $branchId = $this->resolveBranchId($request);

        $printer = Printer::where('branch_id', $branchId)->findOrFail($id);

        $validated = $request->validate([
            'name'       => 'sometimes|string|max:255',
            'ip_address' => 'sometimes|string|max:45',
            'port'       => 'nullable|string|max:10',
            'type'       => 'sometimes|in:CASHIER,KITCHEN,BAR,OTHER',
            'is_active'  => 'sometimes|boolean',
        ]);

        // فحص عدم التكرار عند تغيير IP
        if (isset($validated['ip_address'])) {
            $exists = Printer::where('branch_id', $branchId)
                ->where('ip_address', $validated['ip_address'])
                ->where('id', '!=', $id)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'ip_address' => 'طابعة بهذا العنوان موجودة مسبقاً في هذا الفرع',
                ]);
            }
        }

        $printer->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الطابعة',
            'data'    => $printer->fresh(),
        ]);
    }

    /**
     * حذف طابعة + قواعدها المرتبطة
     */
    public function destroy(Request $request, $id)
    {
        $branchId = $this->resolveBranchId($request);

        $printer = Printer::where('branch_id', $branchId)->findOrFail($id);

        // حذف القواعد المرتبطة أولاً
        PrintRoute::where('printer_id', $id)->delete();
        $printer->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف الطابعة وقواعدها المرتبطة',
        ]);
    }

    /**
     * اختبار الاتصال بالطابعة
     */
    public function testConnection(Request $request, $id)
    {
        $branchId = $this->resolveBranchId($request);

        $printer = Printer::where('branch_id', $branchId)->findOrFail($id);
        $result = $printer->testConnection();

        return response()->json($result);
    }

    /**
     * جلب قواعد التوجيه لطابعة محددة
     */
    public function routes(Request $request, $printerId)
    {
        $branchId = $this->resolveBranchId($request);

        // التأكد من أن الطابعة تابعة للفرع
        Printer::where('branch_id', $branchId)->findOrFail($printerId);

        $routes = PrintRoute::where('printer_id', $printerId)
            ->with(['user', 'category', 'item'])
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $routes,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────

    private function resolveBranchId(Request $request): ?int
    {
        if ($request->has('branch_id')) {
            return (int) $request->branch_id;
        }

        $user = Auth::user();
        if ($user && $user->branch_id) {
            return (int) $user->branch_id;
        }

        $branchHeader = $request->header('X-Branch-Id');
        if ($branchHeader) {
            return (int) $branchHeader;
        }

        // Fallback: أول فرع في قاعدة البيانات (للمستخدمين العامين)
        $firstBranch = \App\Models\Branch::first();
        if ($firstBranch) {
            return (int) $firstBranch->id;
        }

        return null;
    }

    public function testPrint(Request $request, $id, PrinterService $printerService)
    {
        $branchId = $this->resolveBranchId($request);
        $printer = Printer::where('branch_id', $branchId)->findOrFail($id);

        $result = $printerService->testPrint($printer);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    public function printInvoice(Request $request, $orderId, OrderPrintingService $printingService)
    {
        $order = Order::with(['items', 'customer', 'branch'])->findOrFail($orderId);

        $result = $printingService->printInvoiceToCashier($order);

        return response()->json($result, $result['success'] ? 200 : 500);
    }
}
