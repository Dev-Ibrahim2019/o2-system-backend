<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CallCenterRegister extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'device_uuid',
        'activation_token',
        'token_expires_at',
        'status',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    protected static function booted(): void
    {
        static::creating(function (CallCenterRegister $register) {
            if (empty($register->code)) {
                $last = static::orderBy('id', 'desc')->first();
                $nextNumber = $last ? ((int) Str::after($last->code, 'CC-') + 1) : 1;
                $register->code = 'CC-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
            }
        });

        static::updating(function (CallCenterRegister $register) {
            if ($register->isDirty('code')) {
                $register->code = $register->getOriginal('code');
            }
        });
    }
}
