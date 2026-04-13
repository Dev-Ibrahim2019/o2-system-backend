<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $table = 'branche';

    protected $fillable = [
        'name',
        'code',
        'status',
        'parent_id',
        'city',
        'address',
        'google_map_url',
        'phone',
        'whatsapp',
        'email',
        'opening_time',
        'closing_time'
    ];
}
