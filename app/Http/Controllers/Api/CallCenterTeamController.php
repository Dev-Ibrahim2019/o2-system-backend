<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\AuditLog;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * فريق الكول سنتر — إدارة حسابات موظفي الكول سنتر العاديين (role=call-center) من داخل لوحة
 * الكول سنتر نفسها، بدون أي تدخل من لوحة الأدمن العامة. القسم يدير حسابات User حقيقية (تسجّل
 * دخول فعليًا وتحمل صلاحيات Spatie مباشرة) — وليس سجلات Employee الإدارية (نموذج منفصل تمامًا
 * لا يملك Authenticatable ولا يقدر يسجّل دخول أصلاً، راجع ملاحظة routes/api.php).
 *
 * أمان إلزامي:
 * - الدور دائمًا "call-center" فقط — غير قابل للاختيار من هذه الشاشة إطلاقاً (لا مسار لمنح
 *   دور أدمن أو دور خارج نطاق الكول سنتر من هنا).
 * - الصلاحيات القابلة للمنح محصورة بالقائمة المقفلة AGENT_PERMISSIONS فقط — أي قيمة خارجها تُرفض.
 * - لا يقدر أي مستخدم (حتى المدير نفسه) يعدّل/يحذف/يغيّر صلاحيات حسابه الخاص من هذه الشاشة.
 */
class CallCenterTeamController extends ApiController
{
    /** القائمة المقفلة الوحيدة للصلاحيات القابلة للمنح لموظف كول سنتر عادي — لا تُضاف/تُعدَّل
     * إلا من هذا الملف؛ Seeder يقرأ منها مباشرة لتجنّب ازدواجية المصدر. */
    public const AGENT_PERMISSIONS = [
        'call-center.view-active-orders',
        'call-center.create-order',
        'call-center.assign-driver',
        'call-center.change-order-status',
        'call-center.cancel-order',
        'call-center.complete-order',
        'call-center.manual-complete-order',
        'call-center.view-closed-orders',
        'call-center.view-dashboard',
    ];

    /** يُمنح تلقائيًا لكل موظف جديد — نقطة بداية معقولة، قابلة للسحب لاحقًا مثل أي صلاحية أخرى */
    private const DEFAULT_PERMISSIONS = ['call-center.view-active-orders'];

    public function index(): JsonResponse
    {
        // بدون withoutGlobalScope: لو تجاوز branch_id الخاص بالمدير null لأي سبب (خطأ سابق أو
        // تعديل يدوي)، سيختفي عنه موظفو الفروع الأخرى بصمت من هذه الشاشة الإدارية بدل ظهور
        // خطأ واضح — الشاشة مصممة لتُدار مركزيًا بغض النظر عن فرع حساب المدير نفسه.
        $agents = User::withoutGlobalScope(BranchScope::class)
            ->role('call-center')
            ->withTrashed()
            ->with('branch:id,name')
            ->get()
            ->map(fn (User $user) => $this->formatAgent($user))
            ->values();

        return $this->success('فريق الكول سنتر', $agents);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
            // موظف الكول سنتر العادي Department = null دائمًا ومفروض — لا يُقرأ من الطلب أبدًا
            // (وليس مجرد قيمة اختيارية فارغة قابلة للتعديل لاحقًا، حسب متطلبات الفيتشر).
            'branch_id' => null,
        ]);

        // الدور مفروض دائمًا "call-center" — لا يُقرأ من الطلب أبدًا (منع صريح لمنح أي دور آخر
        // من هذه الشاشة، تحديدًا لا مسار لمنح call-center-manager أو أي دور أدمن من هنا).
        $user->syncRoles(['call-center']);
        $user->syncPermissions(self::DEFAULT_PERMISSIONS);

        return $this->success(
            'تمت إضافة الموظف',
            $this->formatAgent($user->fresh()->load('branch:id,name')),
            201
        );
    }

    public function toggleStatus(int $user): JsonResponse
    {
        $user = User::withoutGlobalScope(BranchScope::class)->withTrashed()->findOrFail($user);
        if ($error = $this->guardAgentAction($user)) {
            return $error;
        }

        if ($user->trashed()) {
            $user->restore();
        } else {
            $user->delete();
        }

        return $this->success('تم تحديث حالة الحساب', $this->formatAgent($user->fresh()));
    }

    public function updatePermissions(Request $request, int $user): JsonResponse
    {
        $user = User::withoutGlobalScope(BranchScope::class)->withTrashed()->findOrFail($user);
        if ($error = $this->guardAgentAction($user)) {
            return $error;
        }

        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(self::AGENT_PERMISSIONS)],
        ]);

        $before = $user->getDirectPermissions()->pluck('name')->values()->all();
        $after = array_values(array_unique($data['permissions'] ?? []));

        $user->syncPermissions($after);

        // syncPermissions() يعمل مباشرة على جدول pivot ولا يُطلق أحداث Eloquent عادية، فلن
        // يلتقطها AuditObserver العام تلقائيًا (المستخدم حاليًا فقط من Transaction عبر
        // Auditable trait) — نسجّلها يدويًا بنفس بنية AuditLog الموجودة أصلاً.
        AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'event' => 'permissions_updated',
            'old_values' => ['permissions' => $before],
            'new_values' => ['permissions' => $after],
            'user_id' => Auth::id(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return $this->success('تم تحديث صلاحيات الموظف', $this->formatAgent($user->fresh()));
    }

    /** يمنع تعديل حساب المستخدم نفسه من هذه الشاشة، ويتأكد أن الهدف فعلاً موظف كول سنتر عادي
     * (وليس مدير/أدمن تسلّل بأي شكل) — يرجع JsonResponse لو في مانع، أو null لو الإجراء مسموح. */
    private function guardAgentAction(User $user): ?JsonResponse
    {
        if ($user->id === Auth::id()) {
            return $this->error('لا يمكنك تعديل حسابك الخاص من هذه الشاشة.', 403);
        }
        if (! $user->hasRole('call-center')) {
            return $this->error('هذا الحساب ليس موظف كول سنتر عادي.', 404);
        }

        return null;
    }

    private function formatAgent(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'branch_id' => $user->branch_id,
            'branch_name' => $user->branch?->name,
            'is_active' => ! $user->trashed(),
            'permissions' => $user->getDirectPermissions()->pluck('name')->values()->all(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
