<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\PosRegister;

/**
 * CheckPosNetwork — Middleware لحماية مسارات الكاشير
 * ──────────────────────────────────────────────────
 * يقوم بـ 3 فحوصات أمنية مع كل طلب:
 *   1. التأكد من وجود X-Device-UUID في الهيدر (الجهاز غير مفعّل)
 *   2. التأكد من أن حالة الجهاز لا تزال ACTIVE (لم يتم إلغاؤه)
 *   3. مقارنة نطاق IP الحالي مع Static IP الخاص بالفرع (Subnet /24)
 *
 * الاستخدام: 'check.pos.network'
 */
class CheckPosNetwork
{
    /**
     * معالجة الطلب الوارد
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        // ── 1. جلب UUID الجهاز من الهيدر ─────────────────────────
        $deviceUuid = $request->header('X-Device-UUID');

        // 🛑 فحص (1): هل الـ UUID موجود أصلاً؟ (الجهاز غير مفعّل)
        if (!$deviceUuid) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الجهاز غير مفعّل! يُرجى تفعيل نقطة البيع أولاً عبر كود التفعيل.',
            ], 403);
        }

        // ── 2. البحث عن نقطة البيع مع جلب بيانات الفرع ──────────
        $register = PosRegister::where('device_uuid', $deviceUuid)
            ->with('branch')
            ->first();

        // 🛑 فحص (2): هل الجهاز مسجل في قاعدة البيانات؟
        if (!$register) {
            return response()->json([
                'success' => false,
                'message' => 'معرّف الجهاز غير معروف في النظام! يُرجى إعادة التفعيل.',
            ], 403);
        }

        // 🛑 فحص (3): هل حالة الجهاز لا تزال ACTIVE؟
        if ($register->status !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'تم إلغاء تفعيل هذا الجهاز من قبل الإدارة! يُرجى مراجعة الإدارة لإعادة التفعيل.',
            ], 403);
        }

        // ── 3. فحص الشبكة (Static IP) ───────────────────────────
        if ($register->branch?->static_ip) {
            $clientIp = $request->ip();
            $branchIp = $register->branch->static_ip;

            // استخراج أول 3 خانات (Subnet /24)
            $clientNetwork = implode('.', array_slice(explode('.', $clientIp), 0, 3));
            $branchNetwork = implode('.', array_slice(explode('.', $branchIp), 0, 3));

            // 🛑 فحص (4): هل الجهاز داخل شبكة الفرع؟
            if ($clientNetwork !== $branchNetwork) {
                return response()->json([
                    'success' => false,
                    'message' => 'عذراً، تم حظر الطلب! لا يمكن استخدام نقطة البيع من خارج شبكة الفرع الرسمية.',
                ], 403);
            }
        }

        // ── 4. كل الفحوصات سليمة → مرر الطلب ────────────────────
        return $next($request);
    }
}
