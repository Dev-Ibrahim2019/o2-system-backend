<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CallTicket extends Model
{
    protected $fillable=['external_call_id','branch_id','customer_id','agent_id','linked_order_id','direction','status','incoming_phone','normalized_phone','source','disposition','call_type','satisfaction_rating','satisfaction_feedback','notes','started_at','answered_at','ended_at','duration_seconds','metadata'];
    const TYPE_ORDER='order'; const TYPE_COMPLAINT='complaint'; const TYPE_INQUIRY='inquiry'; const TYPE_MODIFICATION='order_modification';
    const TYPES=[self::TYPE_ORDER,self::TYPE_COMPLAINT,self::TYPE_INQUIRY,self::TYPE_MODIFICATION];
    protected $casts=['metadata'=>'array','started_at'=>'datetime','answered_at'=>'datetime','ended_at'=>'datetime'];
    public function customer(){return $this->belongsTo(Customer::class);} public function branch(){return $this->belongsTo(Branch::class);}
    public function agent(){return $this->belongsTo(User::class,'agent_id');} public function linkedOrder(){return $this->belongsTo(Order::class,'linked_order_id');}
}
