<?php
// app/Models/Department.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'parent_id',
        'type',
        'is_central',
        'is_active',
        'shortName',
        'icon',
        'color',
        'stationNumber',
        'defaultPrepTime',
        'maxConcurrentOrders',
        'hasKds',
        'autoPrintTicket'
    ];

    protected $casts = [
        'is_active'           => 'boolean',
        'hasKds'              => 'boolean',
        'autoPrintTicket'     => 'boolean',
        'maxConcurrentOrders' => 'integer',
        'defaultPrepTime'     => 'integer',
    ];

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_department')
            ->withPivot('is_active')
            ->withTimestamps();
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    // الأب
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    // الأبناء
    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_id');
    }
}
