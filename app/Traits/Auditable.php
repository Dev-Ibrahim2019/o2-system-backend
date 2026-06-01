<?php

namespace App\Traits;

use App\Models\AuditLog;

trait Auditable
{
    protected static function bootAuditable(): void
    {
        // نستخدم Observer منفصل — أنظف وأقل تداخلاً
        static::observe(\App\Observers\AuditObserver::class);
    }
}
