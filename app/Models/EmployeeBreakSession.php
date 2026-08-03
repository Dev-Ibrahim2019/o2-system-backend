<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeBreakSession extends Model
{
    protected $fillable = ['user_id', 'branch_id', 'break_type', 'status', 'started_at', 'ended_at', 'duration_seconds', 'reason'];
    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime', 'duration_seconds' => 'integer'];
}
