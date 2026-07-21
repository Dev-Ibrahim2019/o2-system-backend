<?php
namespace App\Services\Operations;

use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderExecutionEvent;
use App\Models\DeliveryTrip;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderExecutionService
{
    public function startAssembly(Order $order, Employee $assembler, ?User $user = null, ?string $notes = null): Order
    {
        return DB::transaction(function () use ($order,$assembler,$user,$notes) {
            $order=Order::query()->lockForUpdate()->findOrFail($order->id); $this->assertOrderCanAssemble($order); $this->assertAssembler($assembler);
            if ($order->assembly_started_at) throw ValidationException::withMessages(['order'=>'تم بدء تجميع هذا الطلب مسبقاً.']);
            $started=now(); $order->update(['assembly_started_at'=>$started,'assembler_id'=>$assembler->id,'status'=>Order::STATUS_ASSEMBLING]);
            $this->recordEvent($order,'assembly_started',$assembler,$user,$notes,['source'=>'assembler_dashboard'],$started);
            return $this->freshOrder($order);
        });
    }

    public function completeAssembly(Order $order, ?Employee $assembler, ?User $user = null, ?string $notes = null, bool $legacy = false): Order
    {
        return DB::transaction(function () use ($order,$assembler,$user,$notes,$legacy) {
            $order=Order::query()->lockForUpdate()->findOrFail($order->id); $this->assertOrderCanAssemble($order);
            if ($assembler) $this->assertAssembler($assembler);
            if ($order->assembled_at) throw ValidationException::withMessages(['order'=>'تم إنهاء تجميع هذا الطلب مسبقاً.']);
            if ($order->items()->whereNull('item_prepared_at')->whereNotIn('status',['ready','served','cancelled'])->exists()) throw ValidationException::withMessages(['order'=>'يجب استلام جميع الأصناف قبل إنهاء التجميع.']);
            $completed=now(); $started=$order->assembly_started_at; $autoStarted=false;
            if (! $started) { if (! $legacy) throw ValidationException::withMessages(['order'=>'يجب بدء التجميع قبل إنهائه.']); $started=$completed; $autoStarted=true; }
            $duration=max(0,$started->diffInSeconds($completed,false));
            $order->update(['assembly_started_at'=>$started,'assembler_id'=>$order->assembler_id ?: $assembler?->id,'assembled_at'=>$completed,'assembled_by'=>$assembler?->id,'assembly_duration_seconds'=>$duration,'status'=>Order::STATUS_READY_FOR_DELIVERY]);
            $order->tickets()->whereNotIn('status',['cancelled'])->update(['status'=>'ready','ready_at'=>$completed]);
            $order->items()->whereNotIn('status',['cancelled'])->update(['status'=>'ready']);
            $this->recordEvent($order,'assembly_completed',$assembler,$user,$notes,['source'=>$legacy?'legacy_assembled_endpoint':'assembler_dashboard','assembly_duration_seconds'=>$duration,'legacy_auto_start'=>$autoStarted],$completed);
            return $this->freshOrder($order);
        });
    }

    public function recordEvent(Order $order,string $type,?Employee $employee=null,?User $user=null,?string $notes=null,array $metadata=[],mixed $occurredAt=null): OrderExecutionEvent
    {
        return OrderExecutionEvent::create(['order_id'=>$order->id,'event_type'=>$type,'from_status'=>$metadata['from_status']??null,'to_status'=>$metadata['to_status']??$order->status,'employee_id'=>$employee?->id,'user_id'=>$user?->id,'branch_id'=>$order->branch_id,'notes'=>$notes,'metadata'=>$metadata,'occurred_at'=>$occurredAt??now()]);
    }

    public function startDelivery(Order $order, Employee $driver, ?User $user, DeliveryTrip $trip): Order
    {
        abort_unless((int) $driver->branch_id === (int) $order->branch_id, 422, 'المندوب والطلب يجب أن يكونا من الفرع نفسه');
        $from=$order->status; $now=now();
        $order->update(['driver_id'=>$driver->id,'delivery_employee_name'=>$driver->name,'delivery_started_at'=>$now,'delivery_assigned_by'=>$user?->id,'status'=>Order::STATUS_OUT_FOR_DELIVERY]);
        $this->recordEvent($order,'delivery_started',$driver,$user,$trip->notes,['from_status'=>$from,'to_status'=>Order::STATUS_OUT_FOR_DELIVERY,'delivery_trip_id'=>$trip->id,'delivery_trip_number'=>$trip->number],$now);
        return $order->fresh();
    }

    public function completeDelivery(Order $order, ?Employee $driver, ?User $user, DeliveryTrip $trip, ?string $notes=null): Order
    {
        abort_unless($order->status===Order::STATUS_OUT_FOR_DELIVERY,422,'الطلب ليس خارجاً للتوصيل');
        $now=now(); $started=$order->delivery_started_at??$order->assembled_at??$order->updated_at;
        $order->update(['status'=>Order::STATUS_DELIVERED,'delivered_at'=>$now,'delivery_duration_seconds'=>max(0,$started->diffInSeconds($now,false))]);
        $order->tickets()->whereNotIn('status',['cancelled'])->update(['status'=>'served','served_at'=>$now]);
        $order->items()->whereNotIn('status',['cancelled'])->update(['status'=>'served']);
        $this->recordEvent($order,'delivered',$driver,$user,$notes,['from_status'=>Order::STATUS_OUT_FOR_DELIVERY,'to_status'=>Order::STATUS_DELIVERED,'delivery_trip_id'=>$trip->id],$now);
        return $order->fresh();
    }

    public function recoverDelivery(Order $order, ?User $user, DeliveryTrip $trip): Order
    {
        $from=$order->status; $order->update(['driver_id'=>null,'delivery_employee_name'=>null,'delivery_started_at'=>null,'delivery_assigned_by'=>null,'status'=>Order::STATUS_READY_FOR_DELIVERY]);
        $this->recordEvent($order,'delivery_recovered',null,$user,'إلغاء رحلة التوصيل',['from_status'=>$from,'to_status'=>Order::STATUS_PREPARATION,'delivery_trip_id'=>$trip->id]);
        return $order->fresh();
    }

    public function calculateStageTimers(Order $order): array
    {
        $now=$order->delivered_at??now();
        return ['assembly_wait_seconds'=>$order->assembly_started_at?max(0,($order->paid_at??$order->created_at)->diffInSeconds($order->assembly_started_at,false)):null,
            'assembly_duration_seconds'=>$order->assembly_duration_seconds??($order->assembly_started_at&&$order->assembled_at?max(0,$order->assembly_started_at->diffInSeconds($order->assembled_at,false)):null),
            'delivery_wait_seconds'=>$order->assembled_at&&$order->delivery_started_at?max(0,$order->assembled_at->diffInSeconds($order->delivery_started_at,false)):null,
            'delivery_duration_seconds'=>$order->delivery_duration_seconds??($order->delivery_started_at?max(0,$order->delivery_started_at->diffInSeconds($now,false)):null),
            'total_duration_seconds'=>$order->created_at?max(0,$order->created_at->diffInSeconds($now,false)):null];
    }

    private function assertOrderCanAssemble(Order $order): void { if (!in_array($order->status,[Order::STATUS_PREPARATION,Order::STATUS_ASSEMBLING],true)||$order->delivery_started_at||$order->delivered_at) throw ValidationException::withMessages(['order'=>'لا يمكن تجميع الطلب في حالته الحالية.']); }
    private function assertAssembler(Employee $employee): void { if ($employee->operational_role!=='assembler'||!$employee->is_operations_enabled||$employee->status!=='ACTIVE') throw ValidationException::withMessages(['assembler_id'=>'الموظف المحدد ليس مجمع طلبات نشطاً ومفعلاً.']); }
    private function freshOrder(Order $order): Order { return $order->fresh()->load(['items.department','tickets.department','cashier','assembler','assembledByEmployee']); }
}
