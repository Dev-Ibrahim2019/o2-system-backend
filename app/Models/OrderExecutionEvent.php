<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderExecutionEvent extends Model
{
    protected $fillable = ['order_id','event_type','from_status','to_status','employee_id','user_id','branch_id','notes','metadata','occurred_at'];
    protected $casts = ['metadata'=>'array','occurred_at'=>'datetime'];
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
}
