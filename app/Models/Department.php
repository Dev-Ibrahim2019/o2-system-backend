<?php
// app/Models/Department.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'nameAr',
        'shortName',
        'icon',
        'color',
        'type',
        'status',
        'location',
        'stationNumber',
        'defaultPrepTime',
        'maxConcurrentOrders',
        'hasKds',
        'autoPrintTicket',
    ];

    protected $casts = [
        'hasKds'              => 'boolean',
        'autoPrintTicket'     => 'boolean',
        'maxConcurrentOrders' => 'integer',
        'defaultPrepTime'     => 'integer',
    ];
}
