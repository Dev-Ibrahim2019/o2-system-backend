<?php
namespace App\Services\Operations;

use App\Models\Employee;
use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\User;

class DeliveryAssignmentService
{
    public function __construct(private readonly OrderExecutionService $executionService) {}
    private const ACTIVE_STATUSES = [Order::STATUS_OUT_FOR_DELIVERY];

    public function availableDrivers(?int $branchId = null, bool $includeBusy = false): Collection
    {
        return Employee::query()->with('branch:id,name')
            ->where('operational_role', 'delivery_driver')->where('is_operations_enabled', true)
            ->where('status', 'ACTIVE')->whereNotNull('vehicle_type')
            ->withCount([
                'deliveryOrders as active_orders_count' => fn ($q) => $q->whereIn('status', self::ACTIVE_STATUSES),
                'deliveryOrders as today_delivered_orders_count' => fn ($q) => $q->where('status', Order::STATUS_DELIVERED)->whereDate('delivered_at', today()),
            ])
            ->withAvg(['deliveryOrders as average_delivery_seconds' => fn ($q) => $q->whereNotNull('delivery_duration_seconds')], 'delivery_duration_seconds')
            ->when(! $includeBusy, fn ($q) => $q->whereDoesntHave('deliveryOrders', fn ($orders) => $orders->whereIn('status', self::ACTIVE_STATUSES)))
            ->when($branchId, fn ($q) => $q->where(fn ($branch) => $branch->where('branch_id', $branchId)->orWhereNull('branch_id')))
            ->orderByRaw('CASE WHEN branch_id = ? THEN 0 WHEN branch_id IS NULL THEN 1 ELSE 2 END', [$branchId ?: 0])
            ->orderBy('active_orders_count')
            ->orderByRaw("CASE WHEN vehicle_type = 'external' THEN 1 ELSE 0 END")
            ->orderByRaw('average_delivery_seconds IS NULL, average_delivery_seconds ASC')->get()
            ->map(function (Employee $driver) use ($branchId) {
                $driver->setAttribute('calculated_status', $driver->active_orders_count > 0 ? 'busy' : 'available');
                $driver->setAttribute('same_branch', $branchId ? (int) $driver->branch_id === $branchId : true);
                $driver->setAttribute('average_delivery_minutes', $driver->average_delivery_seconds === null ? null : round($driver->average_delivery_seconds / 60, 1));
                return $driver;
            });
    }

    public function assign(Order $order, int $driverId, ?string $notes = null, ?User $user = null): Order
    {
        return DB::transaction(function () use ($order, $driverId, $notes, $user) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $driver = Employee::query()->lockForUpdate()->findOrFail($driverId);
            if (in_array($lockedOrder->status, [Order::STATUS_CANCELLED, Order::STATUS_DELIVERED], true)) throw ValidationException::withMessages(['order' => 'لا يمكن تعيين دليفري لطلب ملغي أو تم تسليمه.']);
            if (! $lockedOrder->assembled_at || $lockedOrder->status !== Order::STATUS_PREPARATION) throw ValidationException::withMessages(['order' => 'يجب إنهاء تجميع الطلب قبل استدعاء الدليفري.']);
            if ($lockedOrder->driver_id) throw ValidationException::withMessages(['order' => 'تم تعيين دليفري لهذا الطلب مسبقاً.']);
            if ($driver->operational_role !== 'delivery_driver' || ! $driver->is_operations_enabled || $driver->status !== 'ACTIVE' || ! $driver->vehicle_type) throw ValidationException::withMessages(['driver_id' => 'الموظف المحدد ليس دليفري نشطاً ومفعلاً بمركبة.']);
            if ($lockedOrder->branch_id && $driver->branch_id && (int) $lockedOrder->branch_id !== (int) $driver->branch_id) throw ValidationException::withMessages(['driver_id' => 'مندوب الدليفري لا ينتمي إلى فرع الطلب.']);
            if (Order::query()->where('driver_id', $driver->id)->whereIn('status', self::ACTIVE_STATUSES)->exists()) throw ValidationException::withMessages(['driver_id' => 'مندوب الدليفري أصبح مشغولاً بطلب آخر.']);
            $from=$lockedOrder->status; $started=now();
            $lockedOrder->update(['driver_id'=>$driver->id,'delivery_employee_name'=>$driver->name,'delivery_started_at'=>$started,'delivery_assigned_by'=>$user?->id,'delivery_notes'=>$notes?:$lockedOrder->delivery_notes,'status'=>Order::STATUS_OUT_FOR_DELIVERY]);
            $meta=['driver_name'=>$driver->name,'vehicle_type'=>$driver->vehicle_type,'from_status'=>$from,'to_status'=>Order::STATUS_OUT_FOR_DELIVERY];
            $this->executionService->recordEvent($lockedOrder,'delivery_assigned',$driver,$user,$notes,$meta,$started);
            $this->executionService->recordEvent($lockedOrder,'delivery_started',$driver,$user,$notes,$meta,$started);
            return $lockedOrder->fresh()->load(['items.department', 'tickets.department', 'cashier', 'deliveryDriver.branch']);
        }, 3);
    }
}
