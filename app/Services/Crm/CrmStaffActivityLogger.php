<?php

namespace App\Services\Crm;

use App\Models\Customer;
use App\Models\CustomerComplaint;
use App\Models\CrmStaffActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * يكتب سجل نشاطات موظفي CRM (crm_staff_activity_logs) — السجل الرقابي الذي
 * يراه مدير CRM والـ super-admin فقط.
 *
 * كل حركة تُسجَّل، والحركات الغريبة تُعلَّم (is_flagged) مع سبب أو أكثر من
 * FLAG_LABELS، حتى يستطيع المدير عرض "الغريب فقط" دون أن يفقد السياق الكامل.
 *
 * الكتابة هنا لا تُفشل العملية الأصلية أبدًا: خطأ في السجل يُكتب في اللوغ
 * ويُبتلع — تعديل عميل أو شكوى أهم من سطر المراقبة عنه.
 */
class CrmStaffActivityLogger
{
    public const ACTION_LABELS = [
        'customer.created' => 'إضافة عميل',
        'customer.updated' => 'تعديل بيانات عميل',
        'complaint.updated' => 'تحديث شكوى',
        'complaint.access_denied' => 'محاولة وصول مرفوضة لشكوى',
        'note.deleted' => 'حذف ملاحظة عميل',
    ];

    public const FLAG_LABELS = [
        'cross_branch_customer_edit' => 'تعديل عميل تابع لفرع آخر',
        'identity_change' => 'تغيير اسم العميل أو رقم هاتفه',
        'customer_blocked' => 'تحويل العميل إلى محظور أو غير نشط',
        'complaint_cancelled' => 'إلغاء شكوى',
        'complaint_not_assignee' => 'تعديل شكوى مسندة لموظف آخر',
        'complaint_declassified' => 'إلغاء تصنيف الحساسية عن شكوى',
        'note_deleted' => 'حذف ملاحظة عميل',
        'out_of_scope_access' => 'محاولة الوصول لشكوى خارج نطاقه',
    ];

    /** Customer columns worth a line in the log when they change. */
    public const TRACKED_CUSTOMER_FIELDS = [
        'name' => 'الاسم',
        'name_en' => 'الاسم بالإنجليزية',
        'title' => 'اللقب',
        'gender' => 'الجنس',
        'phone' => 'الهاتف',
        'mobile' => 'الجوال',
        'email' => 'البريد الإلكتروني',
        'status' => 'الحالة',
        'engagement_status' => 'حالة التعامل',
        'group_id' => 'المجموعة',
        'branch_id' => 'الفرع',
        'city' => 'المدينة',
        'country' => 'الدولة',
        'address' => 'العنوان',
        'notes' => 'ملاحظات',
        'salesperson_id' => 'المندوب',
    ];

    private const IDENTITY_FIELDS = ['name', 'phone', 'mobile'];

    public function __construct(private readonly CrmCustomerAccessService $access) {}

    public function customerCreated(User $actor, Customer $customer): void
    {
        $this->record($actor, 'customer.created', 'customer', $customer->id, [], [
            'customer_name' => $customer->name,
        ], $customer->branch_id, $customer->id);
    }

    /**
     * @param  array<string, mixed>  $before  the tracked columns as they were before the save
     */
    public function customerUpdated(User $actor, Customer $customer, array $before): void
    {
        $changes = [];
        foreach (array_keys(self::TRACKED_CUSTOMER_FIELDS) as $field) {
            $old = $before[$field] ?? null;
            $new = $customer->getAttribute($field);
            if ((string) $old !== (string) $new) {
                $changes[$field] = ['old' => $old, 'new' => $new];
            }
        }

        if ($changes === []) {
            return;
        }

        $flags = [];
        // "Home" branch as it stood before this edit — the edit itself can
        // only move it for a global user, and those are never cross-branch.
        $homeBranch = $before['branch_id'] ?? null;
        if (! $this->access->isGlobal($actor) && $homeBranch !== null && (int) $homeBranch !== (int) $actor->branch_id) {
            $flags[] = 'cross_branch_customer_edit';
        }
        if (array_intersect(self::IDENTITY_FIELDS, array_keys($changes)) !== []) {
            $flags[] = 'identity_change';
        }
        if (isset($changes['status']) && in_array($changes['status']['new'], ['blocked', 'inactive'], true)) {
            $flags[] = 'customer_blocked';
        }

        $this->record($actor, 'customer.updated', 'customer', $customer->id, $flags, [
            'customer_name' => $customer->name,
            'changes' => $changes,
        ], $homeBranch !== null ? (int) $homeBranch : null, $customer->id);
    }

    /**
     * One line per complaint write, carrying what moved.
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    public function complaintUpdated(User $actor, CustomerComplaint $complaint, array $changes, ?int $assigneeBefore): void
    {
        if ($changes === []) {
            return;
        }

        $flags = [];
        if (($changes['status']['new'] ?? null) === CustomerComplaint::STATUS_CANCELLED) {
            $flags[] = 'complaint_cancelled';
        }
        if (isset($changes['is_sensitive']) && ! $changes['is_sensitive']['new']) {
            $flags[] = 'complaint_declassified';
        }
        // Working someone else's ticket. A manager reassigning is their job, so
        // only a holder of the ordinary update permission trips this.
        if ($assigneeBefore !== null && $assigneeBefore !== $actor->id && ! $actor->can('crm.complaints.assign')) {
            $flags[] = 'complaint_not_assignee';
        }

        $this->record($actor, 'complaint.updated', 'complaint', $complaint->id, $flags, [
            'complaint_title' => $complaint->title,
            'changes' => $changes,
        ], $complaint->branch_id !== null ? (int) $complaint->branch_id : null, $complaint->customer_id);
    }

    public function complaintAccessDenied(User $actor, CustomerComplaint $complaint, string $attempt): void
    {
        $this->record($actor, 'complaint.access_denied', 'complaint', $complaint->id, ['out_of_scope_access'], [
            'attempt' => $attempt,
        ], $complaint->branch_id !== null ? (int) $complaint->branch_id : null, $complaint->customer_id);
    }

    public function noteDeleted(User $actor, Customer $customer, string $noteType, string $content): void
    {
        $this->record($actor, 'note.deleted', 'note', null, ['note_deleted'], [
            'customer_name' => $customer->name,
            'note_type' => $noteType,
            'content' => mb_substr($content, 0, 500),
        ], $customer->branch_id, $customer->id);
    }

    private function record(
        User $actor,
        string $action,
        string $subjectType,
        ?int $subjectId,
        array $flags,
        array $details,
        ?int $subjectBranchId,
        ?int $customerId,
    ): void {
        try {
            CrmStaffActivityLog::create([
                'user_id' => $actor->id,
                'actor_branch_id' => $actor->branch_id,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'subject_branch_id' => $subjectBranchId,
                'customer_id' => $customerId,
                'is_flagged' => $flags !== [],
                'flags' => $flags === [] ? null : array_values(array_unique($flags)),
                'details' => $details,
                'ip_address' => request()?->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('CRM staff activity log write failed', [
                'action' => $action,
                'subject_id' => $subjectId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
