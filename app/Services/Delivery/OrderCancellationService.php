<?php
namespace App\Services\Delivery;
use App\Models\{DeliveryTrip,DeliveryTripStop,Employee,Invoice,Order,OrderCancellationRequest,User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class OrderCancellationService
{
    public const REASONS=['customer_cancelled','customer_unreachable','wrong_address','duplicate_order','customer_refused','unsafe_location','vehicle_issue','other'];
    public function request(DeliveryTrip $trip,DeliveryTripStop $stop,User $user,array $data):OrderCancellationRequest
    {
        return DB::transaction(function()use($trip,$stop,$user,$data){
            $lockedTrip=DeliveryTrip::lockForUpdate()->findOrFail($trip->id);$lockedStop=DeliveryTripStop::lockForUpdate()->findOrFail($stop->id);$order=Order::lockForUpdate()->findOrFail($lockedStop->order_id);
            if((int)$lockedStop->delivery_trip_id!==(int)$lockedTrip->id||$lockedTrip->status!=='in_progress'||!in_array($lockedStop->status,['pending','out_for_delivery'],true))throw ValidationException::withMessages(['stop'=>'Stop cannot be cancelled in its current state.']);
            $employee=Employee::where('username',$user->username)->first();
            if(!$user->can('manage-delivery-trips')&&(!$employee||(int)$employee->id!==(int)$lockedTrip->driver_id))throw ValidationException::withMessages(['driver'=>'Only the assigned driver can request cancellation.']);
            if(($data['reason_code']??null)==='other'&&blank($data['reason_text']??null))throw ValidationException::withMessages(['reason_text'=>'Explanation is required for other.']);
            if(OrderCancellationRequest::where('order_id',$order->id)->whereIn('status',['pending','auto_approved'])->lockForUpdate()->exists())throw ValidationException::withMessages(['order'=>'An active cancellation request already exists.']);
            $invoice=Invoice::with('payments')->where('order_id',$order->id)->lockForUpdate()->first();$hasFinancialImpact=$invoice&&($invoice->payments->isNotEmpty()||!in_array($invoice->status,['draft','cancelled'],true));
            $status=$hasFinancialImpact?'pending':'auto_approved';
            $request=OrderCancellationRequest::create(['order_id'=>$order->id,'delivery_trip_id'=>$lockedTrip->id,'delivery_trip_stop_id'=>$lockedStop->id,'requested_by'=>$user->id,'source'=>'delivery_driver','reason_code'=>$data['reason_code'],'reason_text'=>$data['reason_text']??null,'customer_confirmed'=>$data['customer_confirmed']??false,'status'=>$status]);
            if($hasFinancialImpact){$order->update(['status'=>'CANCELLATION_REQUESTED']);$lockedStop->update(['status'=>'cancellation_requested','cancellation_reason'=>$data['reason_code']]);}
            else{$order->update(['status'=>'CANCELLED','cancelled_at'=>now(),'cancellation_reason'=>$data['reason_code']]);$lockedStop->update(['status'=>'cancelled','cancelled_at'=>now(),'cancellation_reason'=>$data['reason_code']]);}
            $this->completeTripIfTerminal($lockedTrip);return $request->load(['order.customer','trip.driver','stop']);
        },3);
    }
    public function approve(OrderCancellationRequest $request,User $reviewer,?string $note):OrderCancellationRequest
    {
        return DB::transaction(function()use($request,$reviewer,$note){$locked=OrderCancellationRequest::lockForUpdate()->findOrFail($request->id);if($locked->status!=='pending')throw ValidationException::withMessages(['request'=>'Request was already reviewed.']);$order=Order::lockForUpdate()->findOrFail($locked->order_id);$stop=$locked->stop()->lockForUpdate()->first();$order->update(['status'=>'CANCELLED','cancelled_at'=>now(),'cancellation_reason'=>$locked->reason_code]);$stop?->update(['status'=>'cancelled','cancelled_at'=>now(),'cancellation_reason'=>$locked->reason_code]);$locked->update(['status'=>'approved','reviewed_by'=>$reviewer->id,'reviewed_at'=>now(),'resolution_note'=>$note]);if($locked->trip)$this->completeTripIfTerminal($locked->trip);return $locked->fresh()->load(['order.customer','trip.driver','stop']);},3);
    }
    public function reject(OrderCancellationRequest $request,User $reviewer,string $decision,?string $note):OrderCancellationRequest
    {
        return DB::transaction(function()use($request,$reviewer,$decision,$note){$locked=OrderCancellationRequest::lockForUpdate()->findOrFail($request->id);if($locked->status!=='pending')throw ValidationException::withMessages(['request'=>'Request was already reviewed.']);$order=Order::lockForUpdate()->findOrFail($locked->order_id);$stop=$locked->stop()->lockForUpdate()->first();$order->update(['status'=>$decision]);$stop?->update(['status'=>$decision==='OUT_FOR_DELIVERY'?'out_for_delivery':'failed','failed_at'=>$decision==='FAILED_DELIVERY'?now():null]);$locked->update(['status'=>'rejected','reviewed_by'=>$reviewer->id,'reviewed_at'=>now(),'resolution_note'=>$note]);return $locked->fresh()->load(['order.customer','trip.driver','stop']);},3);
    }
    private function completeTripIfTerminal(DeliveryTrip $trip):void{if(!$trip->stops()->whereNotIn('status',['delivered','failed','cancellation_requested','cancelled'])->exists())$trip->update(['status'=>'completed','completed_at'=>now()]);}
}
