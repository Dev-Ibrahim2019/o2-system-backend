<?php
// app/Models/Department.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'branch_id',
        'shortName',
        'icon',
        'color',
        'type',
        'status',
        'stationNumber',
        'defaultPrepTime',
        'maxConcurrentOrders',
        'hasKds',
        'autoPrintTicket',
    ];

    protected $casts = [
        'is_active'           => 'boolean',
        'hasKds'              => 'boolean',
        'autoPrintTicket'     => 'boolean',
        'maxConcurrentOrders' => 'integer',
        'defaultPrepTime'     => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function departmentItems(): HasMany
    {
        return $this->hasMany(DepartmentItem::class);
    }

    // فلترة حسب الدور
    public function saleItems(): HasMany
    {
        return $this->departmentItems()->where('role', 'sale');
    }

    public function ingredients(): HasMany
    {
        return $this->departmentItems()->where('role', 'ingredient');
    }

    public function rawMaterials(): HasMany
    {
        return $this->departmentItems()->where('role', 'raw_material');
    }
}
