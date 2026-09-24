<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class CallCenterPaymentPolicy
{
    public function execute(User $user, Order $order): bool
    {
        if ($order->source !== 'call_center') {
            return false;
        }

        // call-center-manager كان ناقص هون رغم إنه بكل مكان تاني (agentCan) بيملك كل صلاحيات الموظف
        return $user->hasAnyRole(['super-admin', 'branch-manager', 'accountant', 'call-center', 'call-center-manager'])
            || $user->can('manage-call-center');
    }
}
