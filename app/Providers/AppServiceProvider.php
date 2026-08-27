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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

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
        //
        Schema::defaultStringLength(191);
    }
}
