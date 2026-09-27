<?php

namespace App\Providers;

use App\Services\Printing\Contracts\PrinterDriverInterface;
use App\Services\Printing\Drivers\EscPosPrinterDriver;
use App\Services\Printing\PrinterService;
use App\Services\Printing\OrderPrintingService;
use App\Services\Printing\Renderers\ArabicReceiptRenderer;
use App\Services\Printing\Renderers\ArabicTextRenderer;
use App\Services\Printing\Renderers\ReceiptImageBuilder;
use App\Services\PrintRoutingService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Event;
use App\Events\OrderPaid;
use App\Listeners\GrantLoyaltyPoints;
use App\Policies\CallCenterPaymentPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PrinterDriverInterface::class, EscPosPrinterDriver::class);
        $this->app->singleton(PrinterService::class);
        $this->app->singleton(PrintRoutingService::class);
        $this->app->singleton(ReceiptImageBuilder::class);
        $this->app->singleton(ArabicReceiptRenderer::class);
        $this->app->singleton(ArabicTextRenderer::class);
        $this->app->singleton(OrderPrintingService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('execute-call-center-payment', [CallCenterPaymentPolicy::class, 'execute']);

        // API التيك أواي الخارجي: حد عام لكل IP قبل المصادقة (محاولات تخمين المفاتيح). الحد لكل مفتاح
        // بـ AuthenticateExternalApiClient نفسه.
        RateLimiter::for('external-takeaway-ip', fn (Request $request) => Limit::perMinute(300)->by('ext-ip:'.$request->ip()));

        // Registered explicitly rather than relied on for auto-discovery —
        // the project's other cross-cutting hooks (Supplier::observe in
        // AccountingServiceProvider) are wired the same explicit way.
        Event::listen(OrderPaid::class, GrantLoyaltyPoints::class);
    }
}
