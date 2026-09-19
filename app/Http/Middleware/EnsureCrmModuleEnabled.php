<?php

namespace App\Http\Middleware;

use App\Models\CrmSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The real enforcement behind crm_settings.enabled — every crm/* route stops
 * here first. Whoever holds crm.settings.manage always passes through
 * regardless of the flag: otherwise switching CRM off would also lock out
 * the only person who could switch it back on, since crm/staff/settings
 * itself lives under this same route group.
 */
class EnsureCrmModuleEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (CrmSetting::current()->enabled || $user?->hasPermissionTo('crm.settings.manage')) {
            return $next($request);
        }

        abort(503, 'وحدة إدارة علاقات العملاء (CRM) معطّلة حاليًا من قبل مدير النظام.');
    }
}
