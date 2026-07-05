<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HospitalityDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'name',
        'device_uuid',
        'activation_token',
        'token_expires_at',
        'status'
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
