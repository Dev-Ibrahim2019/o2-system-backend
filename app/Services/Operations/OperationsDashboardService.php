<?php
namespace App\Services\Operations;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderExecutionEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class OperationsDashboardService
{
    public function __construct(private readonly DeliveryAssignmentService $deliveryService) {}

    public function build(?int $branchId, string $date): array
    {
        $day = Carbon::parse($date);
        $activeStatuses = [Order::STATUS_PENDING_PAYMENT, Order::STATUS_PREPARATION, Order::STATUS_OUT_FOR_DELIVERY];
        $orders = Order::query()->with(['branch:id,name', 'items:id,order_id,item_name,item_name_ar,quantity,status', 'cashier:id,name', 'deliveryDriver:id,name,vehicle_type,branch_id','assembler:id,name','assembledByEmployee:id,name'])
            ->withCount('items')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where(fn ($q) => $q->whereIn('status', $activeStatuses)->orWhereDate('created_at', $day))
            ->latest()->get();

        $orderRows = $orders->map(fn (Order $order) => $this->orderRow($order));
        $fleet = $this->fleet($branchId);
        $staff = $this->staff($branchId, $day, $orders, $fleet);
        $alerts = $this->alerts($orderRows, $fleet, $branchId);

        $active = $orderRows->whereIn('stage', ['PREPARATION','READY_FOR_DELIVERY','OUT_FOR_DELIVERY']);
        $deliveryAverages = $orders->whereNotNull('delivery_duration_seconds')->pluck('delivery_duration_seconds');
        return [
            'kpis' => [
                'active_orders' => $active->count(), 'late_orders' => $active->where('is_late', true)->count(),
                'waiting_assembly' => $orderRows->where('stage', 'PREPARATION')->count(),
                'ready_for_delivery' => $orderRows->where('stage', 'READY_FOR_DELIVERY')->count(),
                'out_for_delivery' => $orderRows->where('stage', 'OUT_FOR_DELIVERY')->count(),
                'available_drivers' => $fleet->where('calculated_status', 'available')->count(),
                'busy_drivers' => $fleet->where('calculated_status', 'busy')->count(),
                'average_delivery_minutes' => $deliveryAverages->isEmpty() ? null : round($deliveryAverages->average() / 60, 1),
            ],
            'orders' => $orderRows->values(), 'staff_summary' => $staff->values(), 'fleet_summary' => $fleet->values(),
            'alerts' => $alerts->values(),
            'branches' => Branch::query()->when($branchId, fn ($q) => $q->whereKey($branchId))->where('is_active', true)->get(['id','name']),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function orderRow(Order $order): array
    {
        $stage = $order->status === Order::STATUS_CANCELLED ? 'CANCELLED'
            : ($order->delivered_at || $order->status === Order::STATUS_DELIVERED ? 'DELIVERED'
            : (($order->driver_id || $order->delivery_started_at) ? 'OUT_FOR_DELIVERY'
            : ($order->assembled_at ? 'READY_FOR_DELIVERY' : 'PREPARATION')));
        $now = now();
        $totalMinutes = max(0, $order->created_at->diffInMinutes($order->delivered_at ?: $now));
        $stageStart = $stage === 'READY_FOR_DELIVERY' ? $order->assembled_at : ($stage === 'OUT_FOR_DELIVERY' ? $order->delivery_started_at : $order->created_at);
        $stageMinutes = $stageStart ? max(0, $stageStart->diffInMinutes($order->delivered_at ?: $now)) : 0;
        $severity = ($stage === 'OUT_FOR_DELIVERY' && $stageMinutes > 45) || $totalMinutes > 60 ? 'critical'
            : (($stage === 'OUT_FOR_DELIVERY' && $stageMinutes > 30) || ($stage === 'READY_FOR_DELIVERY' && $stageMinutes > 5) || ($order->assembly_started_at && ! $order->assembled_at && $order->assembly_started_at->diffInMinutes($now) > 7) ? 'warning' : null);
        return [
            'id'=>$order->id, 'order_number'=>$order->order_number, 'status'=>$order->status, 'stage'=>$stage,
            'customer_name'=>$order->customer_name, 'branch_id'=>$order->branch_id, 'branch'=>$order->branch,
            'total'=>(float)$order->total, 'order_type'=>$order->order_type, 'items_count'=>$order->items_count, 'items'=>$order->items,
            'created_at'=>$order->created_at?->toIso8601String(), 'paid_at'=>$order->paid_at?->toIso8601String(),
            'assembly_started_at'=>$order->assembly_started_at?->toIso8601String(), 'assembler_id'=>$order->assembler_id,
            'assembler'=>$order->assembler ? ['id'=>$order->assembler->id,'name'=>$order->assembler->name] : null,
            'assembled_by_employee'=>$order->assembledByEmployee ? ['id'=>$order->assembledByEmployee->id,'name'=>$order->assembledByEmployee->name] : null,
            'assembly_duration_seconds'=>$order->assembly_duration_seconds,
            'assembled_at'=>$order->assembled_at?->toIso8601String(), 'delivery_started_at'=>$order->delivery_started_at?->toIso8601String(),
            'delivered_at'=>$order->delivered_at?->toIso8601String(), 'delivery_duration_seconds'=>$order->delivery_duration_seconds,
            'driver_id'=>$order->driver_id, 'delivery_employee_name'=>$order->delivery_employee_name,
            'driver'=>$order->deliveryDriver ? ['id'=>$order->deliveryDriver->id,'name'=>$order->deliveryDriver->name,'vehicle_type'=>$order->deliveryDriver->vehicle_type] : null,
            'cashier'=>$order->cashier ? ['id'=>$order->cashier->id,'name'=>$order->cashier->name] : null,
            'total_minutes'=>$totalMinutes, 'stage_minutes'=>$stageMinutes, 'alert_severity'=>$severity, 'is_late'=>$severity !== null,
        ];
    }

    private function fleet(?int $branchId): Collection
    {
        $drivers = $this->deliveryService->availableDrivers($branchId, true);
        $active = Order::query()->where('status', Order::STATUS_OUT_FOR_DELIVERY)->whereNotNull('driver_id')->get(['id','order_number','driver_id','delivery_started_at'])->keyBy('driver_id');
        return $drivers->map(fn (Employee $driver) => [
            'id'=>$driver->id,'name'=>$driver->name,'branch_id'=>$driver->branch_id,'branch'=>$driver->branch,
            'vehicle_type'=>$driver->vehicle_type,'calculated_status'=>$driver->calculated_status,
            'active_orders_count'=>(int)$driver->active_orders_count,'today_delivered_orders_count'=>(int)$driver->today_delivered_orders_count,
            'average_delivery_minutes'=>$driver->average_delivery_minutes,'current_order'=>$active->get($driver->id),
            'delivery_started_at'=>$active->get($driver->id)?->delivery_started_at?->toIso8601String(),
        ]);
    }

    private function staff(?int $branchId, Carbon $day, Collection $orders, Collection $fleet): Collection
    {
        $started=OrderExecutionEvent::query()->where('event_type','assembly_started')->whereDate('occurred_at',$day)->when($branchId,fn($q)=>$q->where('branch_id',$branchId))->selectRaw('employee_id, COUNT(*) aggregate')->groupBy('employee_id')->pluck('aggregate','employee_id');
        $completed=OrderExecutionEvent::query()->where('event_type','assembly_completed')->whereDate('occurred_at',$day)->when($branchId,fn($q)=>$q->where('branch_id',$branchId))->selectRaw('employee_id, COUNT(*) aggregate')->groupBy('employee_id')->pluck('aggregate','employee_id');
        $assemblyStats=Order::query()->whereDate('assembled_at',$day)->whereNotNull('assembled_by')->when($branchId,fn($q)=>$q->where('branch_id',$branchId))->selectRaw('assembled_by, AVG(assembly_duration_seconds) average_seconds')->groupBy('assembled_by')->get()->keyBy('assembled_by');
        return Employee::query()->with(['branch:id,name','department:id,name','jobTitle:id,name'])->where('is_operations_enabled', true)->where('status','ACTIVE')
            ->whereIn('operational_role',['call_center_agent','cashier','assembler','delivery_driver'])->when($branchId, fn($q)=>$q->where('branch_id',$branchId))->get()
            ->map(function(Employee $employee) use($orders,$fleet,$started,$completed,$assemblyStats) {
                $role=$employee->operational_role;
                $owned=$role==='delivery_driver' ? $orders->where('driver_id',$employee->id) : ($role==='call_center_agent' ? $orders->where('call_center_agent_id',$employee->id) : ($role==='assembler' ? collect() : $orders->where('cashier_id',$employee->id)));
                $fleetRow=$fleet->firstWhere('id',$employee->id);
                $currentAssembly=$role==='assembler'?$orders->first(fn($order)=>(int)$order->assembler_id===$employee->id&&$order->assembly_started_at&&!$order->assembled_at):null;
                $average=$role==='assembler'&&isset($assemblyStats[$employee->id])?round($assemblyStats[$employee->id]->average_seconds/60,1):($fleetRow['average_delivery_minutes']??null);
                return ['id'=>$employee->id,'name'=>$employee->name,'branch'=>$employee->branch,'department'=>$employee->department,'job_title'=>$employee->jobTitle,'status'=>$employee->status,
                    'operational_role'=>$role,'vehicle_type'=>$employee->vehicle_type,'current_status'=>$fleetRow['calculated_status']??($owned->whereIn('status',[Order::STATUS_PREPARATION])->isNotEmpty()?'busy':'available'),
                    'today_orders_count'=>$role==='assembler'?(int)($started[$employee->id]??0):$owned->count(),'started_today'=>$role==='assembler'?(int)($started[$employee->id]??0):null,'active_orders_count'=>$role==='assembler'?($currentAssembly?1:0):$owned->whereIn('status',[Order::STATUS_PENDING_PAYMENT,Order::STATUS_PREPARATION,Order::STATUS_OUT_FOR_DELIVERY])->count(),
                    'completed_orders_count'=>$role==='assembler'?(int)($completed[$employee->id]??0):$owned->where('status',Order::STATUS_DELIVERED)->count(),'completed_today'=>$role==='assembler'?(int)($completed[$employee->id]??0):$owned->where('status',Order::STATUS_DELIVERED)->count(),'average_minutes'=>$average,
                    'current_order'=>$currentAssembly?['id'=>$currentAssembly->id,'order_number'=>$currentAssembly->order_number,'assembly_started_at'=>$currentAssembly->assembly_started_at?->toIso8601String()]:($fleetRow['current_order']??null),'has_alert'=>$currentAssembly&&$currentAssembly->assembly_started_at->diffInMinutes(now())>7,'alerts'=>[]];
            });
    }

    private function alerts(Collection $orders, Collection $fleet, ?int $branchId): Collection
    {
        $alerts=collect();
        foreach($orders as $order){ if(!$order['alert_severity']) continue; $assemblyLate=$order['assembly_started_at']&&!$order['assembled_at']&&Carbon::parse($order['assembly_started_at'])->diffInMinutes(now())>7; $message=$assemblyLate?'طلب قيد التجميع منذ أكثر من 7 دقائق':($order['stage']==='READY_FOR_DELIVERY'?'طلب جاهز ينتظر الدليفري منذ أكثر من 5 دقائق':($order['stage']==='OUT_FOR_DELIVERY'?'طلب خارج للتوصيل متأخر':'طلب نشط تجاوز 60 دقيقة')); $alerts->push(['id'=>'order-'.$order['id'].'-'.$order['alert_severity'],'type'=>$assemblyLate?'assembly':$order['stage'],'severity'=>$order['alert_severity'],'title'=>$assemblyLate?'تجميع متأخر':($order['alert_severity']==='critical'?'تأخير حرج':'تنبيه زمني'),'message'=>$message,'order_id'=>$order['id'],'order_number'=>$order['order_number'],'employee_id'=>$assemblyLate?$order['assembler_id']:$order['driver_id']]); }
        if($fleet->where('calculated_status','available')->isEmpty()) $alerts->push(['id'=>'no-drivers','type'=>'fleet','severity'=>'warning','title'=>'أسطول الدليفري','message'=>'لا يوجد دليفري متاح حالياً']);
        $missing=Employee::query()->where('operational_role','delivery_driver')->where('is_operations_enabled',true)->whereNull('vehicle_type')->when($branchId,fn($q)=>$q->where('branch_id',$branchId))->count();
        if($missing) $alerts->push(['id'=>'drivers-missing-vehicle','type'=>'hr','severity'=>'warning','title'=>'بيانات دليفري ناقصة','message'=>"يوجد {$missing} موظف دليفري بدون نوع مركبة"]);
        return $alerts->sortByDesc(fn($alert)=>$alert['severity']==='critical'?2:1);
    }
}
