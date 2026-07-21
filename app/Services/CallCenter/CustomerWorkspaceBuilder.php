<?php
namespace App\Services\CallCenter;
use App\Models\CallTicket;
class CustomerWorkspaceBuilder
{
    public function __construct(private readonly CallCenterService $customers){}
    public function build(CallTicket $ticket,bool $includeFinance=false):array
    {
        $ticket->load(['branch:id,name','agent:id,name','linkedOrder:id,order_number,status,total']);
        if(!$ticket->customer_id)return ['ticket'=>$ticket,'customer'=>null,'customer_candidates'=>$ticket->metadata['customer_candidates']??[]];
        $profile=$this->customers->getCustomerFullProfile($ticket->customer_id);
        if(!$includeFinance&&isset($profile['profile']))unset($profile['profile']['balance'],$profile['profile']['available_credit'],$profile['profile']['is_over_limit']);
        return ['ticket'=>$ticket,'customer'=>$profile,'recent_call_tickets'=>CallTicket::where('customer_id',$ticket->customer_id)->whereKeyNot($ticket->id)->latest('started_at')->limit(5)->get()];
    }
}
