<?php
// app/Models/Employee.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employeeId',
        'name',
        'phone',
        'email',
        'address',
        'nationalId',
        'dob',
        'image',
        'branch_id',
        'department_id',
        'jobTitleId',
        'typeId',
        'managerId',
        'hireDate',
        'salary',
        'role',
        'status',
        'username',
        'password',
        'pin',
        'permissions',
        'notes',
        'rating',
        'performance',
    ];

    protected $hidden = ['password', 'pin'];

    protected $casts = [
        'permissions' => 'array',
        'performance' => 'array',
        'dob'         => 'date',
        'hireDate'    => 'date',
        'salary'      => 'decimal:2',
        'rating'      => 'decimal:1',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}