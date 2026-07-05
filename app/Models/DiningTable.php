<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiningTable extends Model
{
    use HasFactory;

    protected $fillable = [
        'dining_zone_id',
        'table_number',
        'qr_code',
        'qr_url',
        'capacity',
        'status',
    ];

    public function zone()
    {
        return $this->belongsTo(DiningZone::class, 'dining_zone_id');
    }

    public function branch()
    {
        return $this->hasOneThrough(
            Branch::class,
            DiningZone::class,
            'id',      // DiningZone key
            'id',      // Branch key
            'dining_zone_id', // Local key on DiningZone
            'branch_id'       // Local key on Branch
        );
    }

    public function getBranchAttribute()
    {
        return $this->zone?->branch;
    }
}
