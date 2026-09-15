<?php

namespace App\Services\CallCenter;

use App\Models\DeliveryAssignment;
use App\Models\Employee;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * تعيين/تغيير/إلغاء سائق التوصيل — بشكل ذرّي (DB::transaction + lockForUpdate) يحمي من تعارض
 * موظفَي كول سنتر يحاولان تعيين نفس السائق لحظيًا (القسم 11 بالبرومبت). ترتيب القفل ثابت دائمًا
 * (السائق أولاً ثم الطلب) لتفادي deadlock بين معاملتين متزامنتين تتنافسان على نفس المورد.
 *
 * delivery_assignments هو سجل التاريخ الكامل (القسم 12) — orders.driver_id يبقى للوصول السريع
 * فقط ولا يُعتمد عليه وحده لحساب عبء عمل السائق (activeDeliveryAssignmentsCount بالموديل).
 */
class DeliveryAssignmentService
{
    public function __construct(private readonly OrderStatusService $statusService) {}

    public function assign(Order $order, int $driverId): Order
    {
        return DB::transaction(function () use ($order, $driverId) {
            $driver = Employee::where('id', $driverId)->lockForUpdate()->first();
            if (! $driver) {
                throw new InvalidArgumentException('السائق غير موجود.');
            }

            $order = Order::where('id', $order->id)->lockForUpdate()->first();

            if ($order->order_type !== 'delivery') {
                throw new InvalidArgumentException('تعيين موظف توصيل متاح فقط لطلبات التوصيل.');
            }
            if ($order->status !== 'ready') {
                throw new InvalidArgumentException('لا يمكن تعيين موظف توصيل قبل أن يصبح الطلب جاهزًا.');
            }
            if ($order->driver_id) {
                throw new InvalidArgumentException('يوجد سائق معيَّن على هذا الطلب بالفعل — استخدم تغيير السائق.');
            }
            if ($driver->operational_role !== 'delivery_driver') {
                throw new InvalidArgumentException('الموظف المحدد ليس سائق توصيل.');
            }
            // إعادة تحقق كاملة من الأهلية هون — بعد قفل صف السائق — بدل الوثوق بما عرضته الواجهة
            // وقت فتح القائمة (قد تكون بيانات قديمة، القسم 11).
            if (! $driver->isEligibleForNewAssignment()) {
                throw new InvalidArgumentException('هذا السائق لم يعد متاحًا حاليًا. الرجاء اختيار سائق آخر.');
            }

            $this->statusService->assertTransition($order, 'OUT_FOR_DELIVERY');

            DeliveryAssignment::create([
                'order_id' => $order->id,
                'driver_id' => $driver->id,
                'assigned_by' => Auth::id(),
                'assigned_at' => now(),
                'status' => 'active',
            ]);

            $order->update([
                'driver_id' => $driver->id,
                'status' => 'OUT_FOR_DELIVERY',
                'delivery_assigned_at' => now(),
            ]);

            $this->statusService->logStatusChange($order->fresh(), 'ready', 'OUT_FOR_DELIVERY');
            $this->statusService->logDriverAssigned($order, $driver->name);

            return $order->fresh()->load(['items.department', 'driver']);
        });
    }

    /** تغيير السائق (Change Driver) — يحافظ على تاريخ التعيين القديم (reassigned) بدل حذفه. */
    public function reassign(Order $order, int $newDriverId): Order
    {
        return DB::transaction(function () use ($order, $newDriverId) {
            $newDriver = Employee::where('id', $newDriverId)->lockForUpdate()->first();
            if (! $newDriver) {
                throw new InvalidArgumentException('السائق غير موجود.');
            }

            $order = Order::where('id', $order->id)->lockForUpdate()->first();

            if ($order->status !== 'OUT_FOR_DELIVERY' || ! $order->driver_id) {
                throw new InvalidArgumentException('لا يوجد سائق معيَّن على هذا الطلب حاليًا.');
            }
            if ((int) $order->driver_id === $newDriver->id) {
                throw new InvalidArgumentException('هذا السائق مُعيَّن على الطلب بالفعل.');
            }
            if ($newDriver->operational_role !== 'delivery_driver') {
                throw new InvalidArgumentException('الموظف المحدد ليس سائق توصيل.');
            }
            if (! $newDriver->isEligibleForNewAssignment()) {
                throw new InvalidArgumentException('السائق البديل غير متاح حاليًا. الرجاء اختيار سائق آخر.');
            }

            $oldDriverId = $order->driver_id;
            $oldDriverName = $order->driver?->name ?? '—';

            DeliveryAssignment::where('order_id', $order->id)
                ->where('driver_id', $oldDriverId)
                ->where('status', 'active')
                ->update(['status' => 'reassigned', 'released_at' => now()]);

            DeliveryAssignment::create([
                'order_id' => $order->id,
                'driver_id' => $newDriver->id,
                'assigned_by' => Auth::id(),
                'assigned_at' => now(),
                'status' => 'active',
            ]);

            $order->update([
                'driver_id' => $newDriver->id,
                'delivery_assigned_at' => now(),
            ]);

            $fresh = $order->fresh();
            $this->statusService->logDriverUnassigned($fresh, $oldDriverName);
            $this->statusService->logDriverAssigned($fresh, $newDriver->name);

            return $fresh->load(['items.department', 'driver']);
        });
    }

    /** إلغاء تعيين السائق (Unassign) — يرجّع الطلب لحالة "جاهز" بدون سائق. */
    public function unassign(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order = Order::where('id', $order->id)->lockForUpdate()->first();

            if ($order->status !== 'OUT_FOR_DELIVERY' || ! $order->driver_id) {
                throw new InvalidArgumentException('لا يوجد سائق معيَّن على هذا الطلب حاليًا.');
            }

            $this->statusService->assertTransition($order, 'ready');

            $driverName = $order->driver?->name ?? '—';

            DeliveryAssignment::where('order_id', $order->id)
                ->where('status', 'active')
                ->update(['status' => 'released', 'released_at' => now()]);

            $order->update([
                'driver_id' => null,
                'status' => 'ready',
                'delivery_assigned_at' => null,
            ]);

            $fresh = $order->fresh();
            $this->statusService->logStatusChange($fresh, 'OUT_FOR_DELIVERY', 'ready');
            $this->statusService->logDriverUnassigned($fresh, $driverName);

            return $fresh->load(['items.department', 'driver']);
        });
    }

    /** يُستخدم من إتمام التسليم (completed) أو إلغاء الطلب (released) — يضبط حالة التعيين النشط
     * الحالي لو وُجد فقط، دون لمس حالة الطلب نفسها (تلك مسؤولية المستدعي). آمن يُستدعى بأي وقت. */
    public function release(Order $order, string $assignmentStatus): void
    {
        DeliveryAssignment::where('order_id', $order->id)
            ->where('status', 'active')
            ->update(['status' => $assignmentStatus, 'released_at' => now()]);
    }
}
