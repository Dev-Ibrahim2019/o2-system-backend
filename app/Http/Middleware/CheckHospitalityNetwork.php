<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\HospitalityDevice;

/**
 * CheckHospitalityNetwork — Middleware لحماية مسارات الضيافة
 * ──────────────────────────────────────────────────
 * يقوم بـ 3 فحوصات أمنية مع كل طلب:
 *   1. التأكد من وجود X-Device-UUID في الهيدر (الجهاز غير مفعّل)
 *   2. التأكد من أن حالة الجهاز لا تزال ACTIVE (لم يتم إلغاؤه)
 *   3. مقارنة نطاق IP الحالي مع Static IP الخاص بالفرع (Subnet /24)
 *
 * الاستخدام: 'check.hospitality.network'
 */
class CheckHospitalityNetwork
{
    public function handle(Request $request, Closure $next): mixed
    {
        // ── 1. جلب UUID الجهاز من الهيدر ─────────────────────────
        $deviceUuid = $request->header('X-Device-UUID');

        if (!$deviceUuid) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الجهاز غير مفعّل! يُرجى تفعيل جهاز الضيافة أولاً عبر كود التفعيل.',
            ], 403);
        }

        // ── 2. البحث عن جهاز الضيافة مع جلب بيانات الفرع ──────────
        $device = HospitalityDevice::where('device_uuid', $deviceUuid)
            ->with('branch')
            ->first();

        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => 'معرّف الجهاز غير معروف في النظام! يُرجى إعادة التفعيل.',
            ], 403);
        }

        // ── 3. هل حالة الجهاز لا تزال ACTIVE؟
        if ($device->status !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'تم إلغاء تفعيل هذا الجهاز من قبل الإدارة! يُرجى مراجعة الإدارة لإعادة التفعيل.',
            ], 403);
        }

        // ── 4. فحص الشبكة (Static IP) ───────────────────────────
        if ($device->branch?->static_ip) {
            $clientIp = $request->ip();
            $branchIp = $device->branch->static_ip;

            $clientNetwork = implode('.', array_slice(explode('.', $clientIp), 0, 3));
            $branchNetwork = implode('.', array_slice(explode('.', $branchIp), 0, 3));

            if ($clientNetwork !== $branchNetwork) {
                return response()->json([
                    'success' => false,
                    'message' => 'عذراً، تم حظر الطلب! لا يمكن استخدام جهاز الضيافة من خارج شبكة الفرع الرسمية.',
                ], 403);
            }
        }

        // ── 5. كل الفحوصات سليمة → مرر الطلب ────────────────────
        return $next($request);
    }
}
