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
            'name' => 'required|string|max:255|unique:pos_registers,name',
        ], [
            'name.unique' => 'اسم نقطة البيع هذا مستخدم بالفعل! يرجى اختيار اسم آخر.',
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

    // 4ب. حذف سجل نقطة بيع (بعد إلغاء تفعيلها أو قبل ما تتفعل أصلاً)
    public function destroy($id)
    {
        $register = PosRegister::findOrFail($id);

        if ($register->status === 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'نقطة البيع مفعّلة حالياً — ألغِ تفعيلها أولاً قبل الحذف.',
            ], 422);
        }

        $register->delete();

        return response()->json(['success' => true, 'message' => 'تم حذف نقطة البيع بنجاح']);
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

        // ── 2-6: البحث + الفحوصات + التفعيل كلها جوا معاملة واحدة مع قفل الصف
        //      (lockForUpdate) — طلبين متزامنين بنفس الكود الصحيح، بدون القفل،
        //      ممكن الاثنين يعدّوا فحص الصلاحية وينجحوا معاً على نفس نقطة البيع
        //      بجهازين مختلفين. القفل يخلي الطلب الثاني ينتظر لحد ما الأول يمسح
        //      الكود، فبيوصل "الكود مستخدم مسبقاً" بدل ما ينجح خطأً.
        $result = \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            $register = PosRegister::where('activation_token', strtoupper($request->token))
                ->lockForUpdate()
                ->with('branch:id,name,static_ip')
                ->first();

            if (!$register) {
                return ['error' => 'كود التفعيل غير صحيح أو تم استخدامه مسبقاً!'];
            }

            if (!$register->token_expires_at || Carbon::now()->greaterThan($register->token_expires_at)) {
                return ['error' => 'انتهت صلاحية كود التفعيل (15 دقيقة). يُرجى طلب كود جديد من الأدمن.'];
            }

            $clientIp = $request->ip();
            $branchIp = $register->branch?->static_ip;

            if ($branchIp) {
                $clientNetwork = implode('.', array_slice(explode('.', $clientIp), 0, 3));
                $branchNetwork = implode('.', array_slice(explode('.', $branchIp), 0, 3));

                if ($clientNetwork !== $branchNetwork) {
                    return ['error' => "عذراً، جهازك متصل بشبكة خارجية غير مصرح بها! شبكة الجهاز الحالية: ({$clientIp}) والشبكة المطلوبة لفرعك تنتهي بـ: ({$branchNetwork}.X)"];
                }
            }

            $deviceUuid = (string) Str::uuid();

            $register->update([
                'device_uuid'       => $deviceUuid,
                'activation_token'  => null,
                'token_expires_at'  => null,
                'status'            => 'ACTIVE',
            ]);

            return ['register' => $register, 'device_uuid' => $deviceUuid];
        });

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], 422);
        }

        $register = $result['register'];
        $deviceUuid = $result['device_uuid'];

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
