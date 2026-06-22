<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Traits\HasRoles;

class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        // لا نطبق الفلترة إذا:
        // 1. لا يوجد مستخدم مسجل دخول
        // 2. المستخدم super-admin (يرى كل شيء)
        // 3. المستخدم لا يملك branch_id محدداً
        if (
            ! $user
            || $this->isSuperAdmin($user)
            || is_null($user->branch_id)
        ) {
            return;
        }

        $builder->where('branch_id', $user->branch_id);
    }

    private function isSuperAdmin($user): bool
    {
        if (method_exists($user, 'hasRole')) {
            return $user->hasRole('super-admin');
        }
        return false;
    }
}
