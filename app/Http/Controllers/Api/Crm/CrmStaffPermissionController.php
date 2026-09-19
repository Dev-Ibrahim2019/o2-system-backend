<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CrmStaffPermissionDenial;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * Delegated CRM permission management — a crm-manager's own control over
 * what their CRM team (call-center, crm-staff, other crm-manager accounts)
 * can see and do, without needing a super-admin for every change.
 *
 * Four layers of protection keep this from becoming a privilege-escalation
 * hole, or a door into other departments' accounts:
 *   1. Permission scope: only crm.* permissions are ever touched here —
 *      never system-wide permissions (manage-users, view-accounting, ...).
 *   2. Target scope: only TEAM_ROLES accounts are reachable — an accountant
 *      or branch-manager's own crm.access is not this screen's business,
 *      even if someone guesses their user id (assertManageableTarget()).
 *   3. No self-escalation: an actor can only grant a permission they hold
 *      themselves (checked against their own effective permissions), and
 *      crm.staff.manage-permissions — the delegation power itself — can
 *      never be granted, revoked or denied through this controller, by
 *      anyone, super-admin included. It only ever moves with role
 *      membership (see the 2027_02_04_000002 migration / the existing
 *      RoleController).
 *   4. Every write is audit-logged (AuditLog, auditable = the target User)
 *      with the actor, the exact permission names, and before/after state —
 *      the same audit trail CustomerGroupController already writes to.
 */
class CrmStaffPermissionController extends Controller
{
    /** Permissions no one may grant, revoke or deny through this controller — moves only with role membership. */
    private const UNDELEGATABLE = ['crm.staff.manage-permissions'];

    /**
     * "CRM staff" here means the CRM team specifically — not every account
     * that happens to hold crm.access. An accountant or branch-manager holds
     * crm.access for their own department's reasons and answers to their own
     * chain, not to a CRM manager; listing them as a manageable "employee" on
     * this screen would let a CRM manager reach into another department's
     * account. Enforced twice: index() only lists these roles, and every
     * write/read-detail action re-checks the target's role independently —
     * the list is guidance, this check is the actual boundary.
     */
    private const TEAM_ROLES = ['crm-manager', 'call-center', 'crm-staff'];

    /**
     * Labels + grouping for every crm.* permission, single source of truth
     * for both this controller's own validation and the frontend's display —
     * the frontend fetches this via catalog() rather than keeping its own
     * translation map that could drift from what actually exists.
     */
    private const CATALOG = [
        'crm.access' => ['group' => 'عام', 'label' => 'الوصول إلى وحدة CRM', 'sensitive' => false],
        'crm.dashboard.view' => ['group' => 'عام', 'label' => 'عرض لوحة تحكم CRM', 'sensitive' => false],

        'crm.view-customers' => ['group' => 'العملاء', 'label' => 'عرض قائمة العملاء', 'sensitive' => false],
        'crm.create-customers' => ['group' => 'العملاء', 'label' => 'إضافة عميل جديد', 'sensitive' => false],
        'crm.edit-customers' => ['group' => 'العملاء', 'label' => 'تعديل بيانات العميل', 'sensitive' => false],
        'crm.delete-customers' => ['group' => 'العملاء', 'label' => 'حذف عميل', 'sensitive' => false],
        'crm.customer-addresses.view' => ['group' => 'العملاء', 'label' => 'عرض عناوين العميل', 'sensitive' => false],

        'crm.customer-orders.view' => ['group' => 'طلبات العملاء', 'label' => 'عرض طلبات العميل', 'sensitive' => false],
        'crm.customer-orders.manage' => ['group' => 'طلبات العملاء', 'label' => 'إدارة إعدادات تأخر الطلبات', 'sensitive' => false],

        'crm.groups.view' => ['group' => 'المجموعات', 'label' => 'عرض مجموعات العملاء', 'sensitive' => false],
        'crm.groups.create' => ['group' => 'المجموعات', 'label' => 'إنشاء مجموعة', 'sensitive' => false],
        'crm.groups.update' => ['group' => 'المجموعات', 'label' => 'تعديل مجموعة', 'sensitive' => false],
        'crm.groups.delete' => ['group' => 'المجموعات', 'label' => 'حذف مجموعة', 'sensitive' => false],

        'crm.complaints.view' => ['group' => 'الشكاوى', 'label' => 'عرض الشكاوى', 'sensitive' => false],
        'crm.complaints.create' => ['group' => 'الشكاوى', 'label' => 'إنشاء شكوى', 'sensitive' => false],
        'crm.complaints.update' => ['group' => 'الشكاوى', 'label' => 'تحديث حالة الشكوى', 'sensitive' => false],
        'crm.complaints.assign' => ['group' => 'الشكاوى', 'label' => 'إسناد الشكاوى لموظف', 'sensitive' => false],

        'crm.notes.view' => ['group' => 'الملاحظات', 'label' => 'عرض ملاحظات العميل', 'sensitive' => false],
        'crm.notes.create' => ['group' => 'الملاحظات', 'label' => 'إضافة ملاحظة', 'sensitive' => false],
        'crm.notes.update' => ['group' => 'الملاحظات', 'label' => 'تعديل ملاحظة', 'sensitive' => false],
        'crm.notes.delete' => ['group' => 'الملاحظات', 'label' => 'حذف ملاحظة', 'sensitive' => false],
        'crm.view-sensitive-notes' => ['group' => 'الملاحظات', 'label' => 'عرض الملاحظات الحساسة', 'sensitive' => true],

        'crm.occasions.view' => ['group' => 'المناسبات', 'label' => 'عرض المناسبات', 'sensitive' => false],
        'crm.occasions.create' => ['group' => 'المناسبات', 'label' => 'إضافة مناسبة', 'sensitive' => false],
        'crm.occasions.update' => ['group' => 'المناسبات', 'label' => 'تعديل مناسبة', 'sensitive' => false],
        'crm.occasions.delete' => ['group' => 'المناسبات', 'label' => 'حذف مناسبة', 'sensitive' => false],

        'crm.loyalty.view' => ['group' => 'الولاء', 'label' => 'عرض برنامج الولاء', 'sensitive' => false],
        'crm.loyalty.manage' => ['group' => 'الولاء', 'label' => 'إدارة نقاط الولاء', 'sensitive' => false],

        'crm.manage-identity-conflicts' => ['group' => 'تعارضات الهوية', 'label' => 'مراجعة تعارضات هوية العملاء', 'sensitive' => true],

        'crm.view-customer-financial' => ['group' => 'البيانات المالية', 'label' => 'عرض الملخص المالي للعميل', 'sensitive' => true],
        'crm.view-customer-statement' => ['group' => 'البيانات المالية', 'label' => 'عرض كشف حساب العميل', 'sensitive' => true],
        'crm.export-customer-statement' => ['group' => 'البيانات المالية', 'label' => 'تصدير كشف حساب العميل', 'sensitive' => true],
        'crm.manage-customer-credit' => ['group' => 'البيانات المالية', 'label' => 'تعديل رصيد/حد ائتمان العميل', 'sensitive' => true],
    ];

    /**
     * GET /api/crm/staff/permissions-catalog
     */
    public function catalog(): JsonResponse
    {
        $existing = Permission::where('name', 'like', 'crm.%')
            ->whereNotIn('name', self::UNDELEGATABLE)
            ->pluck('name');

        $data = $existing->map(fn (string $name) => [
            'name' => $name,
            'group' => self::CATALOG[$name]['group'] ?? 'أخرى',
            'label' => self::CATALOG[$name]['label'] ?? $name,
            'sensitive' => self::CATALOG[$name]['sensitive'] ?? false,
        ])->sortBy('group')->values();

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/crm/staff
     *
     * The CRM team specifically (see TEAM_ROLES's own comment) — not every
     * crm.access holder. An accountant or branch-manager with crm.access for
     * their own department's reasons is not this screen's business.
     */
    public function index(Request $request): JsonResponse
    {
        // No withoutGlobalScopes(): User's own BranchScope applies here like
        // everywhere else, so a branch-scoped crm-manager only manages staff
        // in their own branch — the same boundary Orders/Customers already
        // enforce, not a new one invented for this screen.
        //
        // The acting user's own row is excluded — assertNotSelf() already
        // refuses any write against yourself, so a manager seeing their own
        // name in "their team" and trying to act on it only ever produced a
        // confusing 403. Nothing here is lost: their own permissions are
        // still visible on their own profile/account screen, just not as an
        // editable row in a list that can never actually act on it.
        $staff = User::query()
            ->where('id', '!=', $request->user()->id)
            ->role(self::TEAM_ROLES)
            ->with('roles:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'branch_id'])
            ->map(function (User $user) {
                $all = $user->getAllPermissions()->pluck('name');

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->pluck('name'),
                    'permissions_count' => $all->filter(fn ($p) => str_starts_with($p, 'crm.'))->count(),
                    'denied_count' => $user->permissionDenials()->count(),
                    'has_financial_access' => $all->intersect(array_keys(array_filter(self::CATALOG, fn ($c) => $c['sensitive'])))->isNotEmpty(),
                ];
            });

        return response()->json(['data' => $staff]);
    }

    /**
     * GET /api/crm/staff/{user}/permissions
     */
    public function show(User $user): JsonResponse
    {
        $this->assertManageableTarget($user);

        $roleNames = $user->roles()->pluck('name');
        $rolePermissions = $user->getPermissionsViaRoles()->pluck('name')->filter(fn ($p) => str_starts_with($p, 'crm.'))->values();
        $directPermissions = $user->getDirectPermissions()->pluck('name')->filter(fn ($p) => str_starts_with($p, 'crm.'))->values();
        $deniedPermissions = $user->permissionDenials()->with('permission:id,name')->get()->pluck('permission.name')->values();
        $effective = $rolePermissions->merge($directPermissions)->unique()->diff($deniedPermissions)->values();

        return response()->json([
            'data' => [
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'roles' => $roleNames],
                'role_permissions' => $rolePermissions,
                'direct_permissions' => $directPermissions,
                'denied_permissions' => $deniedPermissions,
                'effective_permissions' => $effective,
            ],
        ]);
    }

    /**
     * POST /api/crm/staff/{user}/permissions/direct
     *
     * Replaces the target's DIRECT crm.* permissions with exactly the set
     * given (role permissions are untouched — this is the additive override
     * layer only). Every name must be one the acting user themself holds,
     * unless the actor is super-admin.
     */
    public function syncDirect(Request $request, User $user): JsonResponse
    {
        $this->assertNotSelf($request->user(), $user);
        $this->assertManageableTarget($user);

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(Permission::where('name', 'like', 'crm.%')->pluck('name'))],
        ]);

        $incoming = collect($data['permissions']);
        $this->assertDelegatable($request->user(), $incoming, requireOwnPermission: true);

        $before = $user->getDirectPermissions()->pluck('name')->filter(fn ($p) => str_starts_with($p, 'crm.'))->values();

        // One transaction: a failed audit-log write must not leave the
        // permission change committed with the caller told it failed.
        DB::transaction(function () use ($user, $incoming, $request, $before) {
            // Only crm.* direct permissions are replaced — any non-crm direct
            // permission the user already holds (outside this screen's reach)
            // survives untouched.
            $nonCrmDirect = $user->getDirectPermissions()->pluck('name')->reject(fn ($p) => str_starts_with($p, 'crm.'));
            $user->syncPermissions($nonCrmDirect->merge($incoming));

            $this->logChange($request->user(), $user, 'direct', $before->all(), $incoming->all());
        });

        return response()->json(['data' => $this->show($user)->getData(true)['data']]);
    }

    /**
     * POST /api/crm/staff/{user}/permissions/deny
     *
     * Replaces the target's explicit-deny set with exactly the set given —
     * these win over both their role and any direct grant (User::hasPermissionTo()).
     */
    public function syncDenied(Request $request, User $user): JsonResponse
    {
        $this->assertNotSelf($request->user(), $user);
        $this->assertManageableTarget($user);

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(Permission::where('name', 'like', 'crm.%')->pluck('name'))],
        ]);

        $incoming = collect($data['permissions']);
        // Denying never escalates anyone's access — it only takes something
        // away — so, unlike a grant, it is never limited to permissions the
        // acting manager happens to hold themself.
        $this->assertDelegatable($request->user(), $incoming, requireOwnPermission: false);

        $before = $user->permissionDenials()->with('permission:id,name')->get()->pluck('permission.name')->values();

        DB::transaction(function () use ($user, $incoming, $request, $before) {
            $user->permissionDenials()->delete();
            $permissionIds = Permission::whereIn('name', $incoming)->pluck('id', 'name');
            foreach ($incoming as $name) {
                CrmStaffPermissionDenial::create([
                    'user_id' => $user->id,
                    'permission_id' => $permissionIds[$name],
                    'created_by' => $request->user()->id,
                ]);
            }

            $this->logChange($request->user(), $user, 'deny', $before->all(), $incoming->all());
        });

        return response()->json(['data' => $this->show($user)->getData(true)['data']]);
    }

    /**
     * Nobody edits their own CRM permissions through this delegated screen —
     * otherwise a crm-manager could clear a deny another admin placed on
     * them, or grant themselves back something narrowed on purpose. Managing
     * your own account, if ever needed, stays with a super-admin acting
     * through the general Users/Roles screen instead.
     */
    private function assertNotSelf(User $actor, User $target): void
    {
        abort_if($actor->id === $target->id, 403, 'لا يمكنك تعديل صلاحياتك الخاصة من هذه الشاشة.');
    }

    /**
     * The real boundary behind TEAM_ROLES — index() only lists team members,
     * but a URL can name any user id directly, so every action re-checks the
     * target's role independently rather than trusting what the list showed.
     */
    private function assertManageableTarget(User $target): void
    {
        abort_unless($target->hasAnyRole(self::TEAM_ROLES), 403, 'هذا المستخدم ليس ضمن فريق CRM — صلاحياته تُدار من شاشة الأدوار والصلاحيات العامة.');
    }

    /**
     * crm.staff.manage-permissions is never delegatable, granted or denied,
     * by anyone. Beyond that, `requireOwnPermission` gates only grants: a
     * grant the actor doesn't hold themself would be a privilege escalation,
     * but a denial can only ever narrow someone's access, so it carries no
     * such restriction (syncDenied passes false).
     */
    private function assertDelegatable(User $actor, \Illuminate\Support\Collection $permissions, bool $requireOwnPermission): void
    {
        if ($permissions->intersect(self::UNDELEGATABLE)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permissions' => 'صلاحية إدارة صلاحيات الفريق لا يمكن منحها أو سحبها من هنا — هي مرتبطة بالدور فقط.',
            ]);
        }

        if (! $requireOwnPermission || $actor->hasRole('super-admin')) {
            return;
        }

        $actorPermissions = $actor->getAllPermissions()->pluck('name');
        $notHeld = $permissions->diff($actorPermissions);

        if ($notHeld->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permissions' => 'لا يمكنك منح صلاحية لا تملكها أنت بنفسك: ' . $notHeld->implode('، '),
            ]);
        }
    }

    /**
     * audit_logs.event is a fixed enum (created/updated/deleted/restored/
     * posted/cancelled/reversed) — it has no room for a permissions-specific
     * value, so every entry here uses 'updated' and carries its real kind
     * ('direct' or 'deny') inside new_values instead; activity() filters on
     * that, not on event.
     */
    private function logChange(User $actor, User $target, string $kind, array $old, array $new): void
    {
        AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
            'event' => 'updated',
            'old_values' => ['kind' => $kind, 'permissions' => $old],
            'new_values' => ['kind' => $kind, 'permissions' => $new],
            'user_id' => $actor->id,
        ]);
    }

    /**
     * GET /api/crm/staff/{user}/activity
     */
    public function activity(User $user): JsonResponse
    {
        $this->assertManageableTarget($user);

        $events = AuditLog::where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->whereIn('new_values->kind', ['direct', 'deny'])
            ->with('user:id,name')
            ->latest()
            ->take(30)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => 'audit-' . $log->id,
                'kind' => $log->new_values['kind'] ?? null,
                'label' => ($log->new_values['kind'] ?? null) === 'direct' ? 'تم تعديل الصلاحيات الممنوحة' : 'تم تعديل الصلاحيات الممنوعة',
                'old' => $log->old_values['permissions'] ?? [],
                'new' => $log->new_values['permissions'] ?? [],
                'actor' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                'timestamp' => $log->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $events]);
    }
}
