<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DeliveryZone extends Model
{
    protected $fillable=['branch_id','code','name','city','area','base_fee','minimum_order_amount','free_delivery_threshold','estimated_minutes','is_active','notes'];
    protected $casts=['base_fee'=>'decimal:3','minimum_order_amount'=>'decimal:3','free_delivery_threshold'=>'decimal:3','estimated_minutes'=>'integer','is_active'=>'boolean'];
    public function branch(){return $this->belongsTo(Branch::class);}
    public function orders(){return $this->hasMany(Order::class);}
}
