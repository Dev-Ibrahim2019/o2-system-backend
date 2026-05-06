<?php
// app/Models/User.php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'password',
        'employee_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    // ── Relations ────────────────────────────────────────────────────────────

    /**
     * الموظف المرتبط بهذا المستخدم (إذا وُجد)
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * هل المستخدم مرتبط بموظف؟
     */
    public function hasEmployee(): bool
    {
        return !is_null($this->employee_id);
    }

    /**
     * الفرع المرتبط بالمستخدم عبر الموظف
     */
    public function getBranchIdAttribute(): ?int
    {
        return $this->employee?->branch_id;
    }

    /**
     * الدور المرتبط بالمستخدم عبر الموظف
     */
    public function getRoleAttribute(): string
    {
        return $this->employee?->role ?? 'EMPLOYEE';
    }
}
