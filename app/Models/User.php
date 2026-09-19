<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use App\Models\Branch;
use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Builder;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, SoftDeletes;
    use HasRoles {
        hasPermissionTo as private spatieHasPermissionTo;
        getAllPermissions as private spatieGetAllPermissions;
    }

    // تطبيق BranchScope على جميع الاستعلامات
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    private ?\Illuminate\Support\Collection $deniedPermissionNamesCache = null;

    public function permissionDenials()
    {
        return $this->hasMany(CrmStaffPermissionDenial::class);
    }

    /**
     * The explicit "deny" layer over role/direct permissions — see the
     * crm_staff_permission_denials migration for why this exists. Cached per
     * request/instance since hasAnyPermission()/hasAllPermissions() call
     * hasPermissionTo() once per permission checked.
     */
    private function deniedPermissionNames(): \Illuminate\Support\Collection
    {
        return $this->deniedPermissionNamesCache ??= $this->permissionDenials()
            ->join('permissions', 'permissions.id', '=', 'crm_staff_permission_denials.permission_id')
            ->pluck('permissions.name');
    }

    public function hasPermissionTo($permission, $guardName = null): bool
    {
        $name = $permission instanceof \Spatie\Permission\Contracts\Permission ? $permission->name : $permission;

        if (is_string($name) && $this->deniedPermissionNames()->contains($name)) {
            return false;
        }

        return $this->spatieHasPermissionTo($permission, $guardName);
    }

    /**
     * Spatie's own getAllPermissions() (role ∪ direct) is a separate code
     * path from hasPermissionTo() — it does not call it, so overriding only
     * hasPermissionTo() left this one blind to denials. That mattered beyond
     * this controller: AuthController's login/me response builds the
     * frontend's own permission list from this exact method, so a denied
     * permission would still show its button/tab in the UI (a confusing
     * "shows, then 403s on click") even though every real authorization
     * check was already correctly denying it.
     */
    public function getAllPermissions(): \Illuminate\Support\Collection
    {
        $denied = $this->deniedPermissionNames();

        return $this->spatieGetAllPermissions()->reject(
            fn ($permission) => $denied->contains($permission->name)
        )->values();
    }

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'branch_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // علاقة المستخدم بالفرع
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
