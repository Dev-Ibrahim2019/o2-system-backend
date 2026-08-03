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

        return $user->hasAnyRole(['super-admin', 'branch-manager', 'accountant', 'call-center'])
            || $user->can('manage-call-center');
    }
}
