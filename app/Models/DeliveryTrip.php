<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DeliveryTrip extends Model
{
    public const ACTIVE=['ready','in_progress'];
    protected $fillable=['branch_id','driver_id','number','status','max_stops','notes','started_at','completed_at','cancelled_at','cancellation_reason','created_by','assigned_by'];
    protected $casts=['started_at'=>'datetime','completed_at'=>'datetime','cancelled_at'=>'datetime','max_stops'=>'integer'];
    public function branch(){return $this->belongsTo(Branch::class);}
    public function driver(){return $this->belongsTo(Employee::class,'driver_id');}
    public function creator(){return $this->belongsTo(User::class,'created_by');}
    public function stops(){return $this->hasMany(DeliveryTripStop::class)->orderBy('sequence');}
}
