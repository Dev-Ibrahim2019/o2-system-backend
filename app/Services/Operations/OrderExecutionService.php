<?php
namespace App\Services\Operations;

use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderExecutionEvent;
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
            $started=now(); $order->update(['assembly_started_at'=>$started,'assembler_id'=>$assembler->id]);
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
            $order->update(['assembly_started_at'=>$started,'assembler_id'=>$order->assembler_id ?: $assembler?->id,'assembled_at'=>$completed,'assembled_by'=>$assembler?->id,'assembly_duration_seconds'=>$duration,'status'=>Order::STATUS_PREPARATION]);
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

    public function calculateStageTimers(Order $order): array
    {
        $now=$order->delivered_at??now();
        return ['assembly_wait_seconds'=>$order->assembly_started_at?max(0,($order->paid_at??$order->created_at)->diffInSeconds($order->assembly_started_at,false)):null,
            'assembly_duration_seconds'=>$order->assembly_duration_seconds??($order->assembly_started_at&&$order->assembled_at?max(0,$order->assembly_started_at->diffInSeconds($order->assembled_at,false)):null),
            'delivery_wait_seconds'=>$order->assembled_at&&$order->delivery_started_at?max(0,$order->assembled_at->diffInSeconds($order->delivery_started_at,false)):null,
            'delivery_duration_seconds'=>$order->delivery_duration_seconds??($order->delivery_started_at?max(0,$order->delivery_started_at->diffInSeconds($now,false)):null),
            'total_duration_seconds'=>$order->created_at?max(0,$order->created_at->diffInSeconds($now,false)):null];
    }

    private function assertOrderCanAssemble(Order $order): void { if ($order->status!==Order::STATUS_PREPARATION||$order->delivery_started_at||$order->delivered_at) throw ValidationException::withMessages(['order'=>'لا يمكن تجميع طلب ملغي أو مسلم أو خارج للتوصيل.']); }
    private function assertAssembler(Employee $employee): void { if ($employee->operational_role!=='assembler'||!$employee->is_operations_enabled||$employee->status!=='ACTIVE') throw ValidationException::withMessages(['assembler_id'=>'الموظف المحدد ليس مجمع طلبات نشطاً ومفعلاً.']); }
    private function freshOrder(Order $order): Order { return $order->fresh()->load(['items.department','tickets.department','cashier','assembler','assembledByEmployee']); }
}
