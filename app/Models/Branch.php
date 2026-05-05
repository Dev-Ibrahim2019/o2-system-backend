<?php

namespace App\Models;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'address',
        'is_active',
        'phone',
        'code',
        'isMainBranch',
        'closingTime',
        'openingTime'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'branch_department')
            ->withPivot('is_active')
            ->withTimestamps();
    }

    public function activeDepartments(): BelongsToMany
    {
        return $this->departments()->wherePivot('is_active', true);
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'branch_item')
            ->withPivot(['price', 'is_active'])
            ->withTimestamps();
    }

    public function activeItems(): BelongsToMany
    {
        return $this->items()->wherePivot('is_active', true);
    }
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
