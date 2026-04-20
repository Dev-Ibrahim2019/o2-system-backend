<?php
// app/Models/Department.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'is_active',
        'shortName',
        'icon',
        'color',
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

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_department')
                    ->withPivot('is_active')
                    ->withTimestamps();
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