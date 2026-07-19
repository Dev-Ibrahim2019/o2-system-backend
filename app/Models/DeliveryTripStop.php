<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DeliveryTripStop extends Model
{
    protected $fillable=['delivery_trip_id','order_id','sequence','status','delivery_address_snapshot','arrived_at','delivered_at','failed_at','cancelled_at','notes','failure_reason','cancellation_reason'];
    protected $casts=['delivery_address_snapshot'=>'array','arrived_at'=>'datetime','delivered_at'=>'datetime','failed_at'=>'datetime','cancelled_at'=>'datetime'];
    public function trip(){return $this->belongsTo(DeliveryTrip::class,'delivery_trip_id');}
    public function order(){return $this->belongsTo(Order::class);}
}
