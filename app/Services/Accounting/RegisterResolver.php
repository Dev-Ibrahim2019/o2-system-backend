<?php

namespace App\Services\Accounting;

use App\Models\CallCenterRegister;
use App\Models\PosRegister;
use Illuminate\Http\Request;

/**
 * يحدد صندوق المبيعات (POS أو كول سنتر) الذي يعمل عليه المستخدم حالياً
 * عبر هيدر X-Device-UUID المُرسَل تلقائياً من الواجهة الأمامية.
 */
class RegisterResolver
{
    /**
     * @return array{type: string, id: int, name: string}|null
     */
    public function resolveFromRequest(Request $request): ?array
    {
        $deviceUuid = $request->header('X-Device-UUID');
        if (! $deviceUuid) {
            return null;
        }

        $posRegister = PosRegister::where('device_uuid', $deviceUuid)->first();
        if ($posRegister && $posRegister->status === 'ACTIVE') {
            return ['type' => 'pos_register', 'id' => $posRegister->id, 'name' => $posRegister->name];
        }

        $ccRegister = CallCenterRegister::where('device_uuid', $deviceUuid)->first();
        if ($ccRegister && $ccRegister->status === 'ACTIVE') {
            return ['type' => 'call_center_register', 'id' => $ccRegister->id, 'name' => $ccRegister->name];
        }

        return null;
    }
}
