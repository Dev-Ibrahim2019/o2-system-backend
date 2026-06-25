<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PosRegister;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PosRegisterController extends Controller
{
    // 1. عرض جميع نقاط البيع للأدمن
    public function index()
    {
        $registers = PosRegister::with('branch:id,name,static_ip')->get();
        return response()->json(['success' => true, 'data' => $registers]);
    }

    // 2. إنشاء نقطة بيع جديدة — الكود (code) يتولد تلقائياً من الموديل
    public function store(Request $request)
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'name' => 'required|string|max:255',
        ]);

        $register = PosRegister::create([
            'branch_id' => $request->branch_id,
            'name' => $request->name,
            'status' => 'PENDING_ACTIVATION'
        ]);

        return response()->json(['success' => true, 'message' => 'تم إنشاء نقطة البيع بنجاح', 'data' => $register]);
    }

    // 3. الأدمن يولد كود تفعيل مؤقت لمرة واحدة (One-Time Activation Code)
    public function generateActivationToken($id)
    {
        $register = PosRegister::findOrFail($id);

        // قاعدة حديدية: يمنع توليد كود لجهاز مفعّل ومربوط مسبقاً
        if ($register->status === 'ACTIVE' || $register->device_uuid !== null) {
            return response()->json([
                'success' => false,
                'message' => 'هذه النقطة مفعّلة بالفعل على جهاز آخر! قم بإلغاء ربط الجهاز الحالي أولاً.'
            ], 400);
        }

        // منع توليد كود لجهاز ملغي (REVOKED)
        if ($register->status === 'REVOKED') {
            return response()->json([
                'success' => false,
                'message' => 'هذه النقطة ملغاة! أنشئ نقطة بيع جديدة بدلاً من محاولة إعادة تفعيل هذه.'
            ], 400);
        }

        // توليد كود فريد من 6 خانات عشوائية كبيرة (مثل: X89TR4)
        $token = strtoupper(Str::random(6));

        $register->update([
            'activation_token' => $token,
            'token_expires_at' => Carbon::now()->addMinutes(15), // الكود يموت بعد 15 دقيقة
            'status' => 'PENDING_ACTIVATION'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم توليد كود التفعيل بنجاح، صلاحيته 15 دقيقة فقط.',
            'token' => $token
        ]);
    }

    // 4. الأدمن يقوم بإلغاء ربط الجهاز (Device Reset / Revoke) في حال تلف اللابتوب
    public function revokeDevice($id)
    {
        $register = PosRegister::findOrFail($id);

        $register->update([
            'device_uuid' => null, // تصفير ومسح هويّة الجهاز القديم بالكامل
            'activation_token' => null,
            'token_expires_at' => null,
            'status' => 'REVOKED' // حالة ملغى — سترفض أي طلب من هذا الجهاز نهائياً
        ]);

        return response()->json(['success' => true, 'message' => 'تم إلغاء تفعيل الجهاز بنجاح. أي محاولة استخدام له سترفض فوراً.']);
    }

    /**
     * 5. فحص حالة الجهاز — يُستدعى من React Route Guard عند تحميل التطبيق
     *     للتحقق من أن الـ UUID لا يزال ACTIVE ولم يتم إلغاؤه
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

        $register = PosRegister::where('device_uuid', $deviceUuid)
            ->with('branch:id,name,static_ip')
            ->first();

        if (!$register || $register->status !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'تم إلغاء تفعيل هذا الجهاز من قبل الإدارة!',
            ], 403);
        }

        // فحص الشبكة أيضاً
        if ($register->branch?->static_ip) {
            $clientIp = $request->ip();
            $branchIp = $register->branch->static_ip;
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
            'pos_info' => [
                'id' => $register->id,
                'code' => $register->code,
                'name' => $register->name,
                'branch_id' => $register->branch_id,
            ],
        ]);
    }

    /**
     * 6. تفعيل نقطة البيع — يُستدعى من متصفح العميل (Client-side)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function activate(Request $request)
    {
        // ── 1. التحقق من صحة المُدخلات ─────────────────────────
        $validator = validator($request->all(), [
            'token' => 'required|string', // كود التفعيل المُرسل من المستخدم
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

        // ── 2. البحث عن نقطة البيع بواسطة كود التفعيل ──────────
        $register = PosRegister::where('activation_token', strtoupper($request->token))
            ->with('branch:id,name,static_ip')
            ->first();

        // ── 3. فحص (أ): هل الكود صحيح وموجود أصلاً؟ ───────────
        if (!$register) {
            return response()->json([
                'success' => false,
                'message' => 'كود التفعيل غير صحيح أو تم استخدامه مسبقاً!',
            ], 422);
        }

        // ── 4. فحص (ب): هل انتهت صلاحية الكود (15 دقيقة)؟ ────
        if (!$register->token_expires_at || Carbon::now()->greaterThan($register->token_expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'انتهت صلاحية كود التفعيل (15 دقيقة). يُرجى طلب كود جديد من الأدمن.',
            ], 422);
        }

        // 🛑 ── 4 مكرر. فحص (ج): التحقق من نطاق الشبكة (Static IP) للفرع المربوط ────
        $clientIp = $request->ip(); // الـ IP الحالي للجهاز
        $branchIp = $register->branch?->static_ip; // الـ IP المخزن في جدول الفروع

        // نقوم بالفحص فقط إذا كان الفرع يمتلك IP مسجل في قاعدة البيانات
        if ($branchIp) {
            // تفكيك واستخراج أول 3 خانات من الـ IP الحالي (مثال: 192.168.1)
            $clientNetwork = implode('.', array_slice(explode('.', $clientIp), 0, 3));
            // تفكيك واستخراج أول 3 خانات من IP الفرع (مثال: 192.168.1)
            $branchNetwork = implode('.', array_slice(explode('.', $branchIp), 0, 3));

            // إذا اختلف نطاق الشبكة، نمنع التفعيل فوراً قبل توليد الـ UUID
            if ($clientNetwork !== $branchNetwork) {
                return response()->json([
                    'success' => false,
                    'message' => "عذراً، جهازك متصل بشبكة خارجية غير مصرح بها! شبكة الجهاز الحالية: ({$clientIp}) والشبكة المطلوبة لفرعك تنتهي بـ: ({$branchNetwork}.X)",
                ], 422);
            }
        }

        // ── 5. توليد UUID فريد للجهاز (مرة واحدة -不可 تغيير) ──
        $deviceUuid = (string) Str::uuid();

        // ── 6. قفل التفعيل: تحديث السجل ومسح الكود ليموت فوراً ─
        $register->update([
            'device_uuid'       => $deviceUuid,          // هوية الجهاز الفريدة
            'activation_token'  => null,                 // إبطال الكود — استخدام لمرة واحدة فقط
            'token_expires_at'  => null,                 // مسح صلاحية الكود نهائياً
            'status'            => 'ACTIVE',             // تغيير الحالة إلى مفعّل
        ]);

        // ── 7. إرجاع الرد الناجح ─────────────────────────────
        return response()->json([
            'success'   => true,
            'message'   => '✅ تم تفعيل نقطة البيع وربطها بهذا الجهاز بنجاح!',
            'device_uuid' => $deviceUuid,
            'pos_info'  => [
                'id'        => $register->id,
                'code'      => $register->code,
                'name'      => $register->name,
                'branch_id' => $register->branch_id,
            ],
        ], 200);
    }
}
