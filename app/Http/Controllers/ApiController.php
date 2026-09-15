<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponses;

class ApiController extends Controller
{
    use ApiResponses;

    public function include(string $relationship): bool
    {
        $param = request()->get('include');

        if (!isset($param)) {
            return false;
        }

        $includeValues = explode(',', strtolower($param));

        return in_array(strtolower($relationship), $includeValues);
    }

    /**
     * تحقق صلاحية عمليات موظف الكول سنتر — مدير الكول سنتر/الأدمن/مدير الفرع/المحاسب يملكون كل
     * شيء دائمًا (نفس مجموعة الأدوار المُستثناة أصلاً بـ OrderController::canEditClosedOrder)،
     * وغير هؤلاء (موظف الكول سنتر العادي) يُفحص له تحديدًا هل يملك الصلاحية الفردية المطلوبة —
     * القائمة المقفلة الوحيدة الممنوحة له CallCenterTeamController::AGENT_PERMISSIONS، فلا يوجد
     * مسار يمنحه صلاحية خارج هذا النطاق أو صلاحية أدمن.
     */
    protected function agentCan(string $permission): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        if ($user->hasRole(['call-center-manager', 'super-admin', 'branch-manager', 'accountant'])) {
            return true;
        }

        return $user->can($permission);
    }
}
