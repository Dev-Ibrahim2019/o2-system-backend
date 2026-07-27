<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CallCenterRegister;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CallCenterRegisterController extends Controller
{
    public function index()
    {
        $registers = CallCenterRegister::with('branch:id,name,static_ip')->get();
        return response()->json(['success' => true, 'data' => $registers]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'name'      => 'required|string|max:255',
        ]);

        $register = CallCenterRegister::create([
            'branch_id' => $request->branch_id,
            'name'      => $request->name,
            'status'    => 'PENDING_ACTIVATION',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء جهاز الكول سنتر بنجاح',
            'data'    => $register,
        ]);
    }

    public function generateActivationToken($id)
    {
        $register = CallCenterRegister::findOrFail($id);

        if ($register->status === 'REVOKED') {
            return response()->json([
                'success' => false,
                'message' => 'هذا الجهاز ملغى! أنشئ جهازاً جديداً بدلاً منه.',
            ], 400);
        }

        $token = strtoupper(Str::random(6));

        $register->update([
            'activation_token' => $token,
            'token_expires_at' => Carbon::now()->addDays(30),
            'status'           => 'PENDING_ACTIVATION',
            'device_uuid'      => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم توليد كود التفعيل بنجاح، صلاحيته 30 يوم.',
            'token'   => $token,
        ]);
    }

    public function revokeDevice($id)
    {
        $register = CallCenterRegister::findOrFail($id);

        $register->update([
            'device_uuid'       => null,
            'activation_token'  => null,
            'token_expires_at'  => null,
            'status'            => 'REVOKED',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء تفعيل الجهاز بنجاح.',
        ]);
    }
}
