<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\PosRegister;
use App\Models\Printer;
use App\Services\Printing\PrinterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * فتح صندوق النقدية (F9 عند الأمين) — عبر طابعة الكاشير المربوطة بجهاز نقطة البيع.
 */
class CashDrawerController extends ApiController
{
    public function __construct(private readonly PrinterService $printerService) {}

    public function open(Request $request): JsonResponse
    {
        $deviceUuid = $request->header('X-Device-UUID');
        $posRegister = $deviceUuid ? PosRegister::where('device_uuid', $deviceUuid)->first() : null;

        if (!$posRegister) {
            return $this->error('جهاز نقطة البيع غير معرّف — لا يمكن تحديد الطابعة المربوطة.', 422);
        }

        $printer = Printer::where('type', 'CASHIER')
            ->where('linked_pos_register_id', $posRegister->id)
            ->where('is_active', true)
            ->first();

        if (!$printer) {
            return $this->error('لا توجد طابعة كاشير نشطة مربوطة بهذا الجهاز.', 422);
        }

        $result = $this->printerService->openCashDrawer($printer);

        if (!($result['success'] ?? false)) {
            return $this->error($result['message'] ?? 'فشل فتح الصندوق.', 500);
        }

        return $this->success('تم فتح صندوق النقدية');
    }
}
