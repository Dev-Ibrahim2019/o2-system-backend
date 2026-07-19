<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrderCancellationRequest extends Model
{
    protected $fillable=['order_id','delivery_trip_id','delivery_trip_stop_id','requested_by','source','reason_code','reason_text','customer_confirmed','status','reviewed_by','reviewed_at','resolution_note'];
    protected $casts=['customer_confirmed'=>'boolean','reviewed_at'=>'datetime'];
    public function order(){return $this->belongsTo(Order::class);} public function trip(){return $this->belongsTo(DeliveryTrip::class,'delivery_trip_id');}
    public function stop(){return $this->belongsTo(DeliveryTripStop::class,'delivery_trip_stop_id');} public function requester(){return $this->belongsTo(User::class,'requested_by');}
}
