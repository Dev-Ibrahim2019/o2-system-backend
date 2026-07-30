<?php
namespace App\Services\CallCenter;
use App\Models\{CallTicket,Customer,Order,User};
use App\Services\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
class CallTicketService
{
    public function __construct(private readonly PhoneNormalizer $phones){}
    public function receiveIncomingCall(array $data):CallTicket{return $this->open($data+['direction'=>'inbound','status'=>'ringing']);}
    public function openManualTicket(array $data):CallTicket{return $this->open($data+['direction'=>'inbound','status'=>'open']);}
    private function open(array $data):CallTicket
    {
        try {
            return DB::transaction(function()use($data){
                if(!empty($data['external_call_id'])){$existing=CallTicket::where('external_call_id',$data['external_call_id'])->lockForUpdate()->first();if($existing)return $existing;}
                $normalized=$this->phones->normalize($data['phone']);$tail=substr(ltrim($normalized,'+'),-9);
                $matches=Customer::where(fn($q)=>$q->where('phone','like','%'.$tail)->orWhere('mobile','like','%'.$tail))->limit(2)->get();
                return CallTicket::create(['external_call_id'=>$data['external_call_id']??null,'branch_id'=>$data['branch_id'],'customer_id'=>$matches->count()===1?$matches->first()->id:null,'agent_id'=>$data['agent_id']??null,'direction'=>$data['direction'],'status'=>$data['status'],'incoming_phone'=>$data['phone'],'normalized_phone'=>$normalized,'source'=>$data['source']??'manual','started_at'=>$data['started_at']??now(),'metadata'=>$matches->count()>1?['customer_candidates'=>$matches->pluck('id')->all()]:null]);
            },3);
        } catch (QueryException $exception) {
            if (!empty($data['external_call_id']) && in_array($exception->getCode(), ['23000', '23505'], true)) {
                return CallTicket::where('external_call_id', $data['external_call_id'])->firstOrFail();
            }
            throw $exception;
        }
    }
    public function acceptTicket(CallTicket $ticket,User $agent):CallTicket{return $this->transition($ticket,$agent,['status'=>'in_progress','agent_id'=>$agent->id,'answered_at'=>$ticket->answered_at??now()]);}
    public function linkCustomer(CallTicket $ticket,Customer $customer,User $agent):CallTicket{return $this->transition($ticket,$agent,['customer_id'=>$customer->id]);}
    public function linkOrder(CallTicket $ticket,Order $order,User $agent):CallTicket{return $this->transition($ticket,$agent,['linked_order_id'=>$order->id]);}
    public function completeTicket(CallTicket $ticket,User $agent,?string $disposition=null,?string $notes=null):CallTicket{return $this->transition($ticket,$agent,['status'=>'completed','ended_at'=>now(),'duration_seconds'=>max(0,now()->diffInSeconds($ticket->answered_at??$ticket->started_at)),'disposition'=>$disposition,'notes'=>$notes??$ticket->notes]);}
    public function markMissed(CallTicket $ticket):CallTicket{$ticket->update(['status'=>'missed','ended_at'=>now()]);return $ticket->fresh();}
    public function addTicketNote(CallTicket $ticket,User $agent,string $note):CallTicket{return $this->transition($ticket,$agent,['notes'=>trim(($ticket->notes?$ticket->notes."\n":'').$note)]);}
    private function transition(CallTicket $ticket,User $agent,array $attributes):CallTicket{return DB::transaction(function()use($ticket,$agent,$attributes){$locked=CallTicket::lockForUpdate()->findOrFail($ticket->id);if(!$agent->hasRole('super-admin')&&(int)$agent->branch_id!==(int)$locked->branch_id)throw ValidationException::withMessages(['ticket'=>'Ticket belongs to another branch.']);$locked->update($attributes);return $locked->fresh();},3);}
}
