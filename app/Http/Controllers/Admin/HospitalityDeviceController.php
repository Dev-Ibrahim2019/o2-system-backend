<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HospitalityDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class HospitalityDeviceController extends Controller
{
    // 1. عرض جميع أجهزة الضيافة للأدمن
    public function index()
    {
        $devices = HospitalityDevice::with('branch:id,name,static_ip')->get();
        return response()->json(['success' => true, 'data' => $devices]);
    }

    // 2. إنشاء جهاز ضيافة جديد
    public function store(Request $request)
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'name' => 'required|string|max:255',
        ]);

        $device = HospitalityDevice::create([
            'branch_id' => $request->branch_id,
            'name' => $request->name,
            'status' => 'PENDING_ACTIVATION'
        ]);

        return response()->json(['success' => true, 'message' => 'تم إنشاء جهاز الضيافة بنجاح', 'data' => $device]);
    }

    // 3. الأدمن يولد كود تفعيل مؤقت لمرة واحدة
    public function generateActivationToken($id)
    {
        $device = HospitalityDevice::findOrFail($id);

        // قاعدة حديدية: يمنع توليد كود لجهاز مفعّل ومربوط مسبقاً
        if ($device->status === 'ACTIVE' || $device->device_uuid !== null) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الجهاز مفعّل بالفعل على جهاز آخر! قم بإلغاء ربط الجهاز الحالي أولاً.'
            ], 400);
        }

        // منع توليد كود لجهاز ملغي (REVOKED)
        if ($device->status === 'REVOKED') {
            return response()->json([
                'success' => false,
                'message' => 'هذا الجهاز ملغى! أنشئ جهاز ضيافة جديد بدلاً من محاولة إعادة تفعيل هذا.'
            ], 400);
        }

        // توليد كود فريد من 6 خانات عشوائية كبيرة (مثل: X89TR4)
        $token = strtoupper(Str::random(6));

        $device->update([
            'activation_token' => $token,
            'token_expires_at' => Carbon::now()->addMinutes(15),
            'status' => 'PENDING_ACTIVATION'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم توليد كود التفعيل بنجاح، صلاحيته 15 دقيقة فقط.',
            'token' => $token
        ]);
    }

    // 4. الأدمن يقوم بإلغاء ربط الجهاز
    public function revokeDevice($id)
    {
        $device = HospitalityDevice::findOrFail($id);

        $device->update([
            'device_uuid' => null,
            'activation_token' => null,
            'token_expires_at' => null,
            'status' => 'REVOKED'
        ]);

        return response()->json(['success' => true, 'message' => 'تم إلغاء تفعيل جهاز الضيافة بنجاح. أي محاولة استخدام له سترفض فوراً.']);
    }

    /**
     * 5. فحص حالة الجهاز — يُستدعى من React Route Guard
     */
    public function checkStatus(Request $request)
    {
        $deviceUuid = $request->header('X-Device-UUID');

        if (!$deviceUuid) {
            return response()->json([
                'success' => false,
                'message' => 'الجهاز غير مفعّل.',
            ], 403);
        }

        $device = HospitalityDevice::where('device_uuid', $deviceUuid)
            ->with('branch:id,name,static_ip')
            ->first();

        if (!$device || $device->status !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'تم إلغاء تفعيل هذا الجهاز من قبل الإدارة!',
            ], 403);
        }

        // فحص الشبكة أيضاً
        if ($device->branch?->static_ip) {
            $clientIp = $request->ip();
            $branchIp = $device->branch->static_ip;
            $clientNetwork = implode('.', array_slice(explode('.', $clientIp), 0, 3));
            $branchNetwork = implode('.', array_slice(explode('.', $branchIp), 0, 3));

            if ($clientNetwork !== $branchNetwork) {
                return response()->json([
                    'success' => false,
                    'message' => 'الجهاز خارج شبكة الفرع!',
                ], 403);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'الجهاز نشط ومصرح له.',
            'hospitality_info' => [
                'id' => $device->id,
                'name' => $device->name,
                'branch_id' => $device->branch_id,
            ],
        ]);
    }

    /**
     * 6. تفعيل جهاز الضيافة — يُستدعى من متصفح العميل (Client-side)
     */
    public function activate(Request $request)
    {
        // ── 1. التحقق من صحة المُدخلات ─────────────────────────
        $validator = validator($request->all(), [
            'token' => 'required|string',
        ], [
            'token.required' => 'كود التفعيل مطلوب! يرجى إدخال الكود المأخوذ من لوحة الإدارة.',
            'token.string' => 'كود التفعيل يجب أن يكون نصياً صحيحاً.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first('token'),
            ], 422);
        }

        // ── 2. البحث عن جهاز الضيافة بواسطة كود التفعيل ──────────
        $device = HospitalityDevice::where('activation_token', strtoupper($request->token))
            ->with('branch:id,name,static_ip')
            ->first();

        // ── 3. فحص (أ): هل الكود صحيح وموجود أصلاً؟ ───────────
        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => 'كود التفعيل غير صحيح أو تم استخدامه مسبقاً!',
            ], 422);
        }

        // ── 4. فحص (ب): هل انتهت صلاحية الكود (15 دقيقة)؟ ────
        if (!$device->token_expires_at || Carbon::now()->greaterThan($device->token_expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'انتهت صلاحية كود التفعيل (15 دقيقة). يُرجى طلب كود جديد من الأدمن.',
            ], 422);
        }

        // 🛑 ── 4 مكرر. فحص (ج): التحقق من نطاق الشبكة (Static IP) ────
        $clientIp = $request->ip();
        $branchIp = $device->branch?->static_ip;

        if ($branchIp) {
            $clientNetwork = implode('.', array_slice(explode('.', $clientIp), 0, 3));
            $branchNetwork = implode('.', array_slice(explode('.', $branchIp), 0, 3));

            if ($clientNetwork !== $branchNetwork) {
                return response()->json([
                    'success' => false,
                    'message' => "عذراً، جهازك متصل بشبكة خارجية غير مصرح بها! شبكة الجهاز الحالية: ({$clientIp}) والشبكة المطلوبة لفرعك تنتهي بـ: ({$branchNetwork}.X)",
                ], 422);
            }
        }

        // ── 5. توليد UUID فريد للجهاز ──
        $deviceUuid = (string) Str::uuid();

        // ── 6. قفل التفعيل: تحديث السجل ومسح الكود ──
        $device->update([
            'device_uuid'       => $deviceUuid,
            'activation_token'  => null,
            'token_expires_at'  => null,
            'status'            => 'ACTIVE',
        ]);

        // ── 7. إرجاع الرد الناجح ─────────────────────────────
        return response()->json([
            'success'   => true,
            'message'   => 'تم تفعيل جهاز الضيافة وربطه بهذا الجهاز بنجاح!',
            'device_uuid' => $deviceUuid,
            'hospitality_info'  => [
                'id'        => $device->id,
                'name'      => $device->name,
                'branch_id' => $device->branch_id,
            ],
        ], 200);
    }
}
