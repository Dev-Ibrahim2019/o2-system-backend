<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\ApiController;
use App\Models\AccountingSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * إدارة تبويب "أرقام الحسابات" — أرقام حسابات محاسبية عامة على مستوى المنشأة
 * (حساب الخصم المسموح، الضريبة، بدل الخدمة، الحد الأدنى، إيراد المبيعات، الكراسي
 * الإضافية...) تُعرض بشاشة الكاشير للاطلاع فقط، وتُدار من هون.
 */
class AccountingSettingController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->success('أرقام الحسابات', AccountingSetting::all());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*' => 'nullable|string|max:100',
        ]);

        foreach ($validated['settings'] as $key => $value) {
            if (! AccountingSetting::where('key', $key)->exists()) {
                continue; // منع إضافة مفاتيح غير معروفة عبر هالمسار
            }
            AccountingSetting::set($key, $value);
        }

        return $this->success('تم تحديث أرقام الحسابات', AccountingSetting::all());
    }
}
