<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\PosRegister;
use App\Models\HospitalityDevice;

/**
 * CheckPosNetwork — Middleware لحماية مسارات الكاشير والضيافة
 * ──────────────────────────────────────────────────
 * يقوم بـ 3 فحوصات أمنية مع كل طلب:
 *   1. التأكد من وجود X-Device-UUID في الهيدر (الجهاز غير مفعّل)
 *   2. التأكد من أن حالة الجهاز لا تزال ACTIVE (لم يتم إلغاؤه)
 *   3. مقارنة نطاق IP الحالي مع Static IP الخاص بالفرع (Subnet /24)
 *
 * يدعم both POS registers و hospitality devices
 *
 * الاستخدام: 'check.pos.network'
 */
class CheckPosNetwork
{
    public function handle(Request $request, Closure $next): mixed
    {
        // ── 1. جلب UUID الجهاز من الهيدر ─────────────────────────
        $deviceUuid = $request->header('X-Device-UUID');

        if (!$deviceUuid) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الجهاز غير مفعّل! يُرجى تفعيل نقطة البيع أولاً عبر كود التفعيل.',
            ], 403);
        }

        // ── 2. البحث في نقطة البيع أولاً ──────────
        $register = PosRegister::where('device_uuid', $deviceUuid)
            ->with('branch')
            ->first();

        $device = null;
        $branch = null;

        if ($register) {
            $branch = $register->branch;
        } else {
            // ── 3. البحث في أجهزة الضيافة ──────────
            $device = HospitalityDevice::where('device_uuid', $deviceUuid)
                ->with('branch')
                ->first();

            if ($device) {
                $branch = $device->branch;
            }
        }

        // ── 4. هل الجهاز مسجل في قاعدة البيانات؟
        if (!$register && !$device) {
            return response()->json([
                'success' => false,
                'message' => 'معرّف الجهاز غير معروف في النظام! يُرجى إعادة التفعيل.',
            ], 403);
        }

        // ── 5. هل حالة الجهاز لا تزال ACTIVE؟
        if ($register && $register->status !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'تم إلغاء تفعيل هذا الجهاز من قبل الإدارة! يُرجى مراجعة الإدارة لإعادة التفعيل.',
            ], 403);
        }

        if ($device && $device->status !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'تم إلغاء تفعيل هذا الجهاز من قبل الإدارة! يُرجى مراجعة الإدارة لإعادة التفعيل.',
            ], 403);
        }

        // ── 6. فحص الشبكة (Static IP) ───────────────────────────
        if ($branch?->static_ip) {
            $clientIp = $request->ip();
            $branchIp = $branch->static_ip;

            $clientNetwork = implode('.', array_slice(explode('.', $clientIp), 0, 3));
            $branchNetwork = implode('.', array_slice(explode('.', $branchIp), 0, 3));

            if ($clientNetwork !== $branchNetwork) {
                return response()->json([
                    'success' => false,
                    'message' => 'عذراً، تم حظر الطلب! لا يمكن استخدام نقطة البيع من خارج شبكة الفرع الرسمية.',
                ], 403);
            }
        }

        // ── 7. فحص تطابق فرع المستخدم مع فرع الجهاز ─────────────
        $user = $request->user();
        if ($user && !$user->hasRole('super-admin')) {
            if ($register && (int) $user->branch_id !== (int) $register->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'عذراً، أنت لا تنتمي لهذا الفرع! لا يمكنك استخدام هذا الجهاز.',
                ], 403);
            }
            if ($device && (int) $user->branch_id !== (int) $device->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'عذراً، أنت لا تنتمي لهذا الفرع! لا يمكنك استخدام هذا الجهاز.',
                ], 403);
            }
        }

        // ── 8. كل الفحوصات سليمة → مرر الطلب ────────────────────
        return $next($request);
    }
}
