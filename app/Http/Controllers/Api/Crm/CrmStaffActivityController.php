<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CrmStaffActivityLog;
use App\Services\Crm\CrmStaffActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/crm/staff-activity — سجل نشاطات موظفي CRM.
 *
 * رقابي: محمي بـ crm.staff-activity.view (مدير CRM والـ super-admin فقط)،
 * وهذه الصلاحية غير قابلة للتفويض من شاشة صلاحيات الفريق
 * (CrmStaffPermissionController::UNDELEGATABLE) — الموظف المراقَب لا يُمنح
 * رؤية سجل مراقبته. قراءة فقط؛ لا يوجد مسار لتعديل السجل أو حذفه.
 */
class CrmStaffActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'flagged' => ['nullable', 'boolean'],
            'user_id' => ['nullable', 'integer'],
            'action' => ['nullable', Rule::in(array_keys(CrmStaffActivityLogger::ACTION_LABELS))],
            'flag' => ['nullable', Rule::in(array_keys(CrmStaffActivityLogger::FLAG_LABELS))],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = CrmStaffActivityLog::query()
            ->with(['user:id,name,branch_id', 'customer:id,name,code'])
            ->when($request->boolean('flagged'), fn ($q) => $q->where('is_flagged', true))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($filters['flag'] ?? null, fn ($q, $f) => $q->whereJsonContains('flags', $f))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d . ' 23:59:59'))
            ->latest('id')
            ->paginate(min(max((int) ($filters['per_page'] ?? 30), 1), 100));

        $branchIds = $page->getCollection()
            ->flatMap(fn (CrmStaffActivityLog $l) => [$l->actor_branch_id, $l->subject_branch_id])
            ->filter()->unique();
        $branchNames = Branch::query()->whereIn('id', $branchIds)->pluck('name', 'id');

        $page->getCollection()->transform(fn (CrmStaffActivityLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'action_label' => CrmStaffActivityLogger::ACTION_LABELS[$log->action] ?? $log->action,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'customer' => $log->customer ? ['id' => $log->customer->id, 'name' => $log->customer->name, 'code' => $log->customer->code] : null,
            'actor' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
            'actor_branch' => $log->actor_branch_id ? ($branchNames[$log->actor_branch_id] ?? null) : null,
            'subject_branch' => $log->subject_branch_id ? ($branchNames[$log->subject_branch_id] ?? null) : null,
            'is_flagged' => $log->is_flagged,
            'flags' => collect($log->flags ?? [])->map(fn (string $f) => [
                'key' => $f,
                'label' => CrmStaffActivityLogger::FLAG_LABELS[$f] ?? $f,
            ])->values(),
            'details' => $log->details,
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'data' => $page,
            'meta' => [
                'actions' => CrmStaffActivityLogger::ACTION_LABELS,
                'flags' => CrmStaffActivityLogger::FLAG_LABELS,
                'fields' => CrmStaffActivityLogger::TRACKED_CUSTOMER_FIELDS,
            ],
        ]);
    }
}
