<?php

namespace App\Services\CallCenter;

use App\Models\Item;
use App\Models\Order;
use App\Models\OrderActivityLog;
use App\Models\OrderItem;
use App\Models\ProductionTicket;
use App\Models\User;
use App\Services\Invoice\InvoiceFromOrderService;
use App\Services\Order\OrderConfirmationService as KitchenReleaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * إزالة صنف وتعديل طلب كول سنتر مفتوح، بقواعد تختلف حسب مرحلة الطلب:
 *
 *   قبل التنفيذ  : مباشرة بدون سبب — والفاتورة غير المدفوعة بتنبني من جديد.
 *   بعد التنفيذ  : سبب إلزامي، والصنف بيتلغى (مش بينحذف) وبتطلع تذكرة إلغاء للقسم المعني.
 *   بعد الدفع    : ممنوع نهائيًا لأي حدا (حتى المشرف) — المبلغ المحوّل ثابت وما لازم يتغيّر الطلب تحته.
 *                  (عمليًا التنفيذ بيجي بعد الدفع دائمًا، فمسار "بعد التنفيذ" بيخص بيانات قديمة/استثنائية.)
 *
 * الأصناف المضافة بعد التنفيذ بتروح للأقسام بتذكرة جديدة فيها الأصناف الجديدة بس (مش الطلب كامل).
 * قفل التعديل المتزامن (editing_by/editing_until) بيمنع موظفين يعدّلوا نفس الطلب سوا؛ بينتهي لحاله
 * لو الموظف سكّر الصفحة.
 */
class OrderAmendmentService
{
    public const PAID_EDIT_MESSAGE = 'لا يمكن تعديل طلب مدفوع.';
    public const LOCK_TTL_SECONDS = 120;
    private const MIN_REASON_LENGTH = 3;

    public function __construct(
        private readonly KitchenReleaseService $kitchenRelease,
        private readonly InvoiceFromOrderService $invoices,
        private readonly DepartmentTicketPrinter $printer,
    ) {}

    // ── قفل التعديل المتزامن ────────────────────────────────────────────────────────────

    /** يحجز/يجدّد قفل التعديل لهالموظف. بيرمي 409 باسم اللي عم يعدّل لو موظف تاني ماسك القفل. */
    public function acquireLock(Order $order, User $by): array
    {
        return DB::transaction(function () use ($order, $by) {
            $locked = Order::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);
            $this->assertEditable($locked);
            if ($holder = $this->lockHolderOtherThan($locked, $by)) {
                throw new ConflictHttpException("الطلب قيد التعديل من {$holder}.");
            }
            $locked->update(['editing_by' => $by->id, 'editing_until' => now()->addSeconds(self::LOCK_TTL_SECONDS)]);

            return ['editing_by' => $by->id, 'editing_until' => $locked->fresh()->editing_until];
        });
    }

    public function releaseLock(Order $order, User $by): void
    {
        $fresh = Order::query()->withoutGlobalScopes()->find($order->id);
        if ($fresh && (int) $fresh->editing_by === (int) $by->id) {
            $fresh->update(['editing_by' => null, 'editing_until' => null]);
        }
    }

    private function lockHolderOtherThan(Order $order, User $by): ?string
    {
        if (! $order->editing_by || (int) $order->editing_by === (int) $by->id
            || ! $order->editing_until || $order->editing_until->isPast()) {
            return null;
        }

        return User::find($order->editing_by)?->name ?? 'موظف آخر';
    }

    // ── الإزالة والتعديل ───────────────────────────────────────────────────────────────

    /** إزالة صنف كامل (كل كميته) من طلب مفتوح. */
    public function removeItem(Order $order, OrderItem $item, User $by, ?string $reason): array
    {
        if ((int) $item->order_id !== (int) $order->id) {
            throw ValidationException::withMessages(['item' => ['الصنف لا ينتمي لهذا الطلب.']]);
        }

        return $this->apply($order, $by, $reason, [['op' => 'remove', 'row' => $item->id, 'quantity' => (float) $item->quantity]]);
    }

    /**
     * تعديل الطلب: الأصناف المطلوبة بشكلها النهائي [{item_id, quantity, notes?}] (0 = إزالة) وملاحظات الطلب.
     * بنحسب الفرق عن الحالة الحالية ونطبّقه بنفس القواعد أعلاه.
     *
     * @param  array{items: array<int, array{item_id:int, quantity:float|int, notes?:?string}>, notes?:?string}  $payload
     */
    public function edit(Order $order, User $by, array $payload, ?string $reason): array
    {
        return $this->apply($order, $by, $reason, null, $payload);
    }

    private function apply(Order $order, User $by, ?string $reason, ?array $explicitChanges, ?array $payload = null): array
    {
        $created = [];
        $result = DB::transaction(function () use ($order, $by, $reason, $explicitChanges, $payload, &$created) {
            $locked = Order::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);

            $this->assertEditable($locked);
            if ($holder = $this->lockHolderOtherThan($locked, $by)) {
                throw new ConflictHttpException("الطلب قيد التعديل من {$holder}.");
            }

            $changes = $explicitChanges ?? $this->diff($locked, $payload['items'] ?? []);
            $hasRemovals = collect($changes)->contains(fn ($c) => $c['op'] !== 'add');
            $hasAdditions = collect($changes)->contains(fn ($c) => $c['op'] === 'add');
            $notesChanged = $payload !== null && array_key_exists('notes', $payload) && ($payload['notes'] ?? null) !== $locked->note;

            $executed = OrderFlowService::executionStatus($locked) === 'executed';
            $reason = trim((string) $reason);

            if ($hasRemovals && $executed) {
                $this->requireReason($reason, 'سبب الإزالة بعد التنفيذ إلزامي.');
            }
            if (! $hasRemovals && ! $hasAdditions && ! $notesChanged) {
                return ['changed' => false, 'warnings' => []];
            }

            $cancelLines = [];
            $summary = [];
            foreach ($changes as $change) {
                if ($change['op'] === 'add') {
                    $name = $this->addRow($locked, $change, $by);
                    $summary[] = "إضافة: {$name} ×{$change['quantity']}";
                    continue;
                }
                [$name, $deptId, $wasSent] = $this->reduceRow($change, $reason);
                $summary[] = "إزالة: {$name} ×{$change['quantity']}";
                if ($wasSent) {
                    $cancelLines[$deptId][] = ['name' => $name, 'quantity' => $change['quantity'], 'action' => 'cancel', 'notes' => $reason ?: null];
                }
            }
            if ($notesChanged) {
                $locked->update(['note' => $payload['notes']]);
                $summary[] = 'تعديل ملاحظات الطلب';
            }

            $locked->recalculateTotals();
            $invoice = $this->invoices->resync($locked->fresh(), $by->id);

            // أصناف أضيفت بعد التنفيذ: تروح للأقسام بتذكرة بالأصناف الجديدة بس (release بيرسل غير المرسل فقط)
            $ticketsBefore = $locked->tickets()->pluck('id');
            if ($hasAdditions && $executed) {
                $this->kitchenRelease->release($locked->fresh());
            }
            $created = $locked->tickets()->whereNotIn('id', $ticketsBefore)->pluck('id')->all();

            foreach ($cancelLines as $deptId => $lines) {
                $created[] = ProductionTicket::create([
                    'order_id' => $locked->id,
                    'department_id' => $deptId,
                    'ticket_number' => ProductionTicket::generateTicketNumber((int) $deptId),
                    'status' => 'served', // معلوماتية: ما بتدخل دورة تحضير المطبخ ولا بتعطّل ready/served للطلب
                    'sent_at' => now(),
                    'type' => ProductionTicket::TYPE_CANCELLATION,
                    'lines' => $lines,
                    'notes' => $reason ?: null,
                    'created_by' => $by->id,
                ])->id;
            }

            OrderActivityLog::create([
                'order_id' => $locked->id,
                'actor_id' => $by->id,
                'action_type' => 'edited',
                'note' => implode(' — ', $summary).($reason !== '' ? " (السبب: {$reason})" : ''),
                'created_at' => now(),
            ]);

            // فاتورة عليها دفعة جزئية (مش مدفوعة بالكامل) ما بتنبني من جديد — منبّه إنه المتبقي تغيّر
            $fresh = $locked->fresh();
            $warnings = [];
            $invoiceRow = $fresh->invoice()->first();
            if ($invoiceRow && $invoice === null && $invoiceRow->payments()->exists()
                && abs((float) $fresh->total - (float) $invoiceRow->total) > 0.001) {
                $warnings[] = sprintf('الإجمالي الجديد (%s) لا يطابق الفاتورة (%s) — عليها دفعة جزئية مسجّلة.', number_format((float) $fresh->total, 2), number_format((float) $invoiceRow->total, 2));
            }

            return ['changed' => true, 'warnings' => $warnings];
        }, 3);

        // الطباعة بعد الـcommit وبره الـtransaction: فشل طابعة ما بيرجّع التعديل، بيتسجّل على التذكرة.
        $printed = [];
        if (config('call-center.print_on_execute', true)) {
            foreach (ProductionTicket::query()->with('department')->whereIn('id', $created)->get() as $ticket) {
                $printed[] = $this->printer->printTicket($order->fresh(), $ticket);
            }
        }

        return ['changed' => $result['changed'], 'warnings' => $result['warnings'], 'change_tickets' => $printed];
    }

    /** الفرق بين الأصناف الحالية والمطلوبة → قائمة عمليات (add / remove / decrease) على أسطر الطلب. */
    private function diff(Order $order, array $wanted): array
    {
        $rows = $order->items()->where('status', '!=', 'cancelled')->with('ticketItem')->get()->groupBy('item_id');
        $targets = collect($wanted)->groupBy('item_id')->map(fn ($g) => [
            'quantity' => (float) $g->sum('quantity'),
            'notes' => $g->first()['notes'] ?? null,
        ]);

        $changes = [];
        foreach ($rows->keys()->merge($targets->keys())->unique() as $itemId) {
            $itemRows = $rows[$itemId] ?? collect();
            $current = (float) $itemRows->sum('quantity');
            $target = (float) ($targets[$itemId]['quantity'] ?? 0);
            $delta = round($target - $current, 3);

            if ($delta > 0) {
                $changes[] = ['op' => 'add', 'item_id' => (int) $itemId, 'quantity' => $delta, 'notes' => $targets[$itemId]['notes'] ?? null];
            } elseif ($delta < 0) {
                $toRemove = -$delta;
                // بنشيل من الأسطر غير المرسلة أول (ما بتحتاج تذكرة إلغاء)، وبعدها المرسلة
                foreach ($itemRows->sortBy(fn ($r) => $r->ticketItem ? 1 : 0) as $row) {
                    if ($toRemove <= 0) {
                        break;
                    }
                    $take = min($toRemove, (float) $row->quantity);
                    $changes[] = ['op' => $take >= (float) $row->quantity ? 'remove' : 'decrease', 'row' => $row->id, 'quantity' => $take];
                    $toRemove -= $take;
                }
            }
        }

        return $changes;
    }

    /** @return array{0:string,1:?int,2:bool} [اسم الصنف، القسم، هل كان مرسل للأقسام] */
    private function reduceRow(array $change, string $reason): array
    {
        $row = OrderItem::query()->with('ticketItem')->findOrFail($change['row']);
        $name = $row->item_name_ar ?: $row->item_name;
        $wasSent = $row->ticketItem !== null;
        $remaining = round((float) $row->quantity - (float) $change['quantity'], 3);

        if ($remaining <= 0) {
            if ($wasSent) {
                $row->ticketItem->delete();
                $row->update(['status' => 'cancelled', 'cancel_reason' => $reason ?: null]);
            } else {
                $row->delete();
            }
        } else {
            $row->update(['quantity' => $remaining, 'total' => round((float) $row->price * $remaining, 2)]);
            $row->ticketItem?->update(['quantity' => (int) ceil($remaining)]);
        }

        return [$name, $row->department_id, $wasSent];
    }

    private function addRow(Order $order, array $change, User $by): string
    {
        $item = Item::findOrFail($change['item_id']);
        $price = $item->priceForBranch($order->branch_id);
        if ($price === null || ! $item->department_id) {
            throw ValidationException::withMessages(['items' => ["الصنف {$item->name} غير متاح في الفرع المحدد."]]);
        }

        OrderItem::create([
            'order_id' => $order->id,
            'created_by' => $by->id,
            'created_at' => now(),
            'item_id' => $item->id,
            'department_id' => $item->department_id,
            'item_name' => $item->name,
            'item_name_ar' => $item->name_ar ?? $item->name,
            'price' => $price,
            'original_price' => $price,
            'final_price' => $price,
            'quantity' => $change['quantity'],
            'total' => round($price * $change['quantity'], 2),
            'status' => 'pending',
            'notes' => $change['notes'] ?? null,
        ]);

        return $item->name_ar ?? $item->name;
    }

    /** الطلب لازم يكون كول سنتر، مفتوح، ومش مدفوع — نفس الفحص للقفل والإزالة والتعديل. */
    private function assertEditable(Order $order): void
    {
        if ($order->source !== 'call_center') {
            throw ValidationException::withMessages(['order' => ['هذا الإجراء لطلبات الكول سنتر فقط.']]);
        }
        if (OrderFlowService::lifecycle($order) !== 'open') {
            throw ValidationException::withMessages(['order' => ['لا يمكن تعديل طلب مغلق أو ملغى.']]);
        }
        if (OrderFlowService::paymentState($order) === 'paid') {
            throw ValidationException::withMessages(['order' => [self::PAID_EDIT_MESSAGE]]);
        }
    }

    private function requireReason(string $reason, string $message): void
    {
        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw ValidationException::withMessages(['reason' => [$message]]);
        }
    }
}
