<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\SipAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * إدارة حسابات SIP (بيانات اعتماد سماعة الموظف) — التخزين فقط.
 * لا يوجد هنا أي اتصال فعلي بسيرفر SIP ولا قيم افتراضية حقيقية؛ الإدخال يدوي بالكامل من الواجهة.
 */
class SipAccountController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $accounts = SipAccount::query()
            ->with('user:id,name')
            ->when($request->user() && ! $request->user()->hasRole('super-admin'), fn ($q) => $q->where('branch_id', $request->user()->branch_id))
            ->orderByDesc('id')
            ->get()
            ->map(fn (SipAccount $a) => $this->present($a));

        return $this->success('SIP accounts fetched', $accounts);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_name' => 'required|string|max:255',
            'username' => 'required|string|max:255',
            'password' => 'required|string|max:255',
            'sip_server' => 'required|string|max:255',
            'websocket_port' => 'nullable|integer|min:1|max:65535',
            'server_path' => 'nullable|string|max:100',
            'domain' => 'nullable|string|max:255',
            'transport' => 'nullable|in:udp,tcp,tls',
            'register_refresh' => 'nullable|integer|min:30|max:3600',
            'keep_alive' => 'nullable|integer|min:5|max:300',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        $account = SipAccount::create([
            ...$data,
            'transport' => $data['transport'] ?? 'udp',
            'register_refresh' => $data['register_refresh'] ?? 300,
            'keep_alive' => $data['keep_alive'] ?? 15,
            'is_active' => true,
            'branch_id' => $request->user()?->branch_id,
        ]);

        return $this->success('SIP account created', $this->present($account), 201);
    }

    public function update(Request $request, SipAccount $sipAccount): JsonResponse
    {
        $data = $request->validate([
            'account_name' => 'required|string|max:255',
            'username' => 'required|string|max:255',
            'password' => 'nullable|string|max:255',
            'sip_server' => 'required|string|max:255',
            'websocket_port' => 'nullable|integer|min:1|max:65535',
            'server_path' => 'nullable|string|max:100',
            'domain' => 'nullable|string|max:255',
            'transport' => 'nullable|in:udp,tcp,tls',
            'register_refresh' => 'nullable|integer|min:30|max:3600',
            'keep_alive' => 'nullable|integer|min:5|max:300',
            'is_active' => 'nullable|boolean',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $sipAccount->update($data);

        return $this->success('SIP account updated', $this->present($sipAccount->fresh()));
    }

    public function destroy(SipAccount $sipAccount): JsonResponse
    {
        $sipAccount->delete();

        return $this->success('SIP account deleted', null);
    }

    /**
     * بيانات اعتماد حساب SIP الخاص بالمستخدم الحالي فقط — يتضمّن كلمة السر (بخلاف
     * index() التي تُخفيها دائماً)، لأن هذا الاستدعاء يُستخدم حصرياً لتزويد سماعة
     * الموظف نفسه ببياناته للتسجيل التلقائي على سيرفر SIP، وليس لعرضها لأي شخص آخر.
     */
    public function myCredentials(Request $request): JsonResponse
    {
        $account = SipAccount::query()
            ->where('user_id', $request->user()?->id)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            return $this->error('لا يوجد حساب SIP مرتبط بحسابك بعد — يجب على المشرف ربطه من صفحة إعدادات SIP.', 404);
        }

        return $this->success('SIP credentials fetched', [
            'account_name' => $account->account_name,
            'username' => $account->username,
            'password' => $account->password,
            'sip_server' => $account->sip_server,
            'websocket_port' => $account->websocket_port,
            'server_path' => $account->server_path,
            'domain' => $account->domain,
            'transport' => $account->transport,
            'register_refresh' => $account->register_refresh,
            'keep_alive' => $account->keep_alive,
        ]);
    }

    /**
     * لا يوجد حالياً أي تتبع حي لتسجيل السماعة (Browser-Phone غير مدمج بعد)،
     * لذلك is_registered ثابتة false بدل بيانات وهمية.
     */
    private function present(SipAccount $account): array
    {
        return [
            'id' => $account->id,
            'account_name' => $account->account_name,
            'username' => $account->username,
            'sip_server' => $account->sip_server,
            'websocket_port' => $account->websocket_port,
            'server_path' => $account->server_path,
            'domain' => $account->domain,
            'transport' => $account->transport,
            'register_refresh' => $account->register_refresh,
            'keep_alive' => $account->keep_alive,
            'is_active' => $account->is_active,
            'is_registered' => false,
            'user_id' => $account->user_id,
            'user_name' => $account->user?->name,
            'created_at' => optional($account->created_at)->toIso8601String(),
        ];
    }
}
