<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobTitle extends Model
{
    protected $fillable = [
        'name',
        'name_ar',
        'name_en',
        'description',
        'department_id',
        'default_operational_role',
        'requires_vehicle',
        'is_active',
    ];

    protected $casts = ['requires_vehicle' => 'boolean', 'is_active' => 'boolean'];

    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function employees(): HasMany { return $this->hasMany(Employee::class); }
}
