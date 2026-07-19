<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use ReflectionClass;

class SystemController extends Controller
{
    public function extensions(): JsonResponse
    {
        $extensions = [];

        // ── 1. Internal Domain Modules (detected from controllers) ──
        $moduleMap = $this->detectModules();
        foreach ($moduleMap as $id => $module) {
            $extensions[] = $this->buildExtension($id, $module);
        }

        // ── 2. Third-party Packages (from composer.json) ──
        $packages = $this->detectPackages();
        foreach ($packages as $pkg) {
            $extensions[] = $this->buildPackageExtension($pkg);
        }

        return response()->json([
            'success' => true,
            'data' => $extensions,
            'meta' => [
                'total' => count($extensions),
                'enabled' => count(array_filter($extensions, fn($e) => $e['status'] === 'enabled')),
                'disabled' => count(array_filter($extensions, fn($e) => $e['status'] === 'disabled')),
                'missing' => count(array_filter($extensions, fn($e) => $e['status'] === 'missing')),
                'healthy' => count(array_filter($extensions, fn($e) => $e['health'] === 'healthy')),
                'failed' => count(array_filter($extensions, fn($e) => $e['health'] === 'failed')),
                'scanned_at' => now()->toISOString(),
            ],
        ]);
    }

    private function detectModules(): array
    {
        $base = base_path('app/Http/Controllers/Api');
        $modules = [];

        // Module definitions: id => config
        $definitions = [
            'pos' => [
                'name' => 'نقطة البيع',
                'name_en' => 'Point of Sale',
                'description' => 'إدارة الطلبات والمبيعات والطاولات',
                'version' => '1.0.0',
                'controllers' => ['OrderController', 'InvoiceController', 'InvoiceDetailsController', 'SettleController', 'PaymentMethodController', 'ProductionTicketController'],
                'models' => ['Order', 'OrderItem', 'Invoice', 'InvoiceItem', 'Payment', 'PaymentMethod', 'ProductionTicket', 'ProductionTicketItem', 'DiningTable', 'DiningZone'],
                'services' => ['Services/Order', 'Services/Invoice'],
                'route_prefix' => '/api/orders',
                'permissions' => ['manage-items', 'access-pos-interface'],
                'config_files' => [],
                'migrations' => ['2026_09_28_000001_create_orders_table', '2026_06_21_000001_create_invoices_table'],
            ],
            'accounting' => [
                'name' => 'النظام المحاسبي',
                'name_en' => 'Accounting System',
                'description' => 'المحاسبة المزدوجة القيود اليومية دفتر الأستاذ',
                'version' => '2.0.0',
                'controllers' => ['Api/Accounting/Accountcontroller', 'Api/Accounting/Transactioncontroller', 'Api/Accounting/Costcentercontroller'],
                'models' => ['Account', 'Transaction', 'Entry', 'CostCenter', 'AccountingPeriod'],
                'services' => ['Services/Accounting'],
                'route_prefix' => '/api/accounting',
                'permissions' => ['manage-accounting', 'view-accounting'],
                'config_files' => [],
                'migrations' => ['2026_04_08_180000_create_accounts_table', '2026_05_06_000000_create_transactions_table'],
            ],
            'sales_invoices' => [
                'name' => 'فواتير المبيعات',
                'name_en' => 'Sales Invoices',
                'description' => 'إدارة فواتير المبيعات والفوترة المتقدمة',
                'version' => '1.5.0',
                'controllers' => ['SalesInvoiceController'],
                'models' => ['SalesInvoice', 'SalesInvoiceItem', 'SalesInvoicePayment'],
                'services' => ['Services/SalesInvoice'],
                'route_prefix' => '/api/sales-invoices',
                'permissions' => ['manage-invoices'],
                'config_files' => [],
                'migrations' => ['2026_06_28_000001_create_sales_invoices_table'],
            ],
            'purchase_bills' => [
                'name' => 'فواتير المشتريات',
                'name_en' => 'Purchase Bills',
                'description' => 'إدارة فواتير المشتريات من الموردين',
                'version' => '1.0.0',
                'controllers' => ['PurchaseBillController'],
                'models' => ['PurchaseBill', 'PurchaseBillItem', 'PurchaseBillAttachment'],
                'services' => [],
                'route_prefix' => '/api/purchase-bills',
                'permissions' => ['manage-invoices'],
                'config_files' => [],
                'migrations' => ['2026_12_13_000001_create_purchase_bills_table'],
            ],
            'vouchers' => [
                'name' => 'السندات المحاسبية',
                'name_en' => 'Vouchers',
                'description' => 'سندات القبض والصرف وإدارة الدفعات',
                'version' => '1.0.0',
                'controllers' => ['VoucherController'],
                'models' => ['Voucher', 'VoucherAllocation'],
                'services' => [],
                'route_prefix' => '/api/vouchers',
                'permissions' => ['view-accounting'],
                'config_files' => [],
                'migrations' => ['2026_07_11_000003_create_vouchers_table'],
            ],
            'quotes' => [
                'name' => 'عروض الأسعار',
                'name_en' => 'Price Quotes',
                'description' => 'إنشاء وإدارة عروض الأسعار وتحويلها لفواتير',
                'version' => '1.0.0',
                'controllers' => ['QuoteController'],
                'models' => ['Quote', 'QuoteItem'],
                'services' => [],
                'route_prefix' => '/api/quotes',
                'permissions' => ['manage-invoices'],
                'config_files' => [],
                'migrations' => ['2026_07_11_000001_create_quotes_table'],
            ],
            'suppliers' => [
                'name' => 'إدارة الموردين',
                'name_en' => 'Suppliers',
                'description' => 'حسابات الموردين وال帳款 والكشوفات',
                'version' => '1.5.0',
                'controllers' => ['SupplierFinancialController'],
                'models' => ['Supplier'],
                'services' => ['Services/Accounting/SupplierAccountingService'],
                'route_prefix' => '/api/suppliers',
                'permissions' => ['manage-suppliers'],
                'config_files' => [],
                'migrations' => ['2026_07_01_000003_create_suppliers_table'],
            ],
            'customers' => [
                'name' => 'إدارة العملاء',
                'name_en' => 'Customers',
                'description' => 'حسابات العملاء وإدارة العملاء',
                'version' => '1.5.0',
                'controllers' => ['CustomerFinancialController'],
                'models' => ['Customer'],
                'services' => ['Services/Accounting/CustomerAccounting'],
                'route_prefix' => '/api/customers',
                'permissions' => ['manage-customers'],
                'config_files' => [],
                'migrations' => ['2026_06_02_000004_create_customers_table'],
            ],
            'employees' => [
                'name' => 'إدارة الموظفين',
                'name_en' => 'Employees',
                'description' => 'إدارة الموظفين والقروض والرواتب',
                'version' => '1.0.0',
                'controllers' => ['EmployeeController', 'EmployeeFinancialController', 'JobTitleController'],
                'models' => ['Employee', 'EmployeeLoan', 'JobTitle'],
                'services' => ['Services/Accounting/EmployeeAccounting', 'Services/Accounting/EmployeeModule', 'Services/Accounting/EmployeeStatement'],
                'route_prefix' => '/api/employees',
                'permissions' => ['manage-employees'],
                'config_files' => [],
                'migrations' => ['2026_04_10_000000_create_employees_table'],
            ],
            'branches' => [
                'name' => 'إدارة الأفرع',
                'name_en' => 'Branches',
                'description' => 'إدارة الفروع والأقسام',
                'version' => '1.0.0',
                'controllers' => ['BranchController', 'DepartmentController'],
                'models' => ['Branch', 'Department'],
                'services' => [],
                'route_prefix' => '/api/branches',
                'permissions' => ['manage-branches', 'manage-departments'],
                'config_files' => [],
                'migrations' => ['2026_04_08_170300_create_branches_table'],
            ],
            'items' => [
                'name' => 'إدارة الأصناف',
                'name_en' => 'Items',
                'description' => 'إدارة الأصناف والمنتجات والقائمة',
                'version' => '1.0.0',
                'controllers' => ['ItemController', 'MenuController'],
                'models' => ['Item'],
                'services' => [],
                'route_prefix' => '/api/items',
                'permissions' => ['manage-items'],
                'config_files' => [],
                'migrations' => ['2026_04_13_080536_create_items_table'],
            ],
            'discounts' => [
                'name' => 'نظام الخصومات',
                'name_en' => 'Discount Engine',
                'description' => 'محرك الخصومات والاستراتيجيات والاستثناءات',
                'version' => '1.2.0',
                'controllers' => ['DiscountController'],
                'models' => ['Discount', 'DiscountTarget', 'DiscountSetting', 'DiscountExclusion', 'DiscountUsageLog'],
                'services' => ['Services/Discount'],
                'route_prefix' => '/api/discounts',
                'permissions' => ['manage-items'],
                'config_files' => [],
                'migrations' => ['2026_06_23_161717_create_discounts_table'],
            ],
            'hospitality' => [
                'name' => 'الضيافة',
                'name_en' => 'Hospitality',
                'description' => 'أجهزة الضيافة والقاعات والطاولات',
                'version' => '1.0.0',
                'controllers' => ['Admin/HospitalityDeviceController', 'Admin/DiningZoneController'],
                'models' => ['HospitalityDevice', 'DiningZone', 'DiningTable'],
                'services' => [],
                'route_prefix' => '/api/hospitality',
                'permissions' => ['manage-hospitality-devices', 'manage-dining-zones'],
                'config_files' => [],
                'migrations' => ['2026_07_02_123645_create_hospitality_devices_table'],
            ],
            'users' => [
                'name' => 'إدارة المستخدمين',
                'name_en' => 'User Management',
                'description' => 'المستخدمين والأدوار والصلاحيات',
                'version' => '1.0.0',
                'controllers' => ['UserController', 'RoleController'],
                'models' => ['User'],
                'services' => [],
                'route_prefix' => '/api/users',
                'permissions' => ['manage-users'],
                'config_files' => ['permission.php'],
                'migrations' => ['2026_06_18_150953_create_permission_tables'],
            ],
            'audit' => [
                'name' => 'سجل التدقيق',
                'name_en' => 'Audit Log',
                'description' => 'تتبع التغييرات وسجل المراجعة',
                'version' => '1.0.0',
                'controllers' => [],
                'models' => ['AuditLog'],
                'services' => [],
                'route_prefix' => null,
                'permissions' => ['view-audit-log'],
                'config_files' => [],
                'migrations' => ['2026_09_21_000000_create_audit_logs_table'],
            ],
            'pos_registers' => [
                'name' => 'نقاط البيع',
                'name_en' => 'POS Registers',
                'description' => 'إدارة نقاط البيع والسجلات',
                'version' => '1.0.0',
                'controllers' => ['Admin/PosRegisterController'],
                'models' => ['PosRegister'],
                'services' => [],
                'route_prefix' => '/api/pos-registers',
                'permissions' => ['manage-pos-registers'],
                'config_files' => [],
                'migrations' => ['2026_06_22_172247_create_pos_registers_table'],
            ],
            'freepbx' => [
                'name' => 'FreePBX',
                'name_en' => 'FreePBX Integration',
                'description' => 'تكامل الهاتف المجاني عبر FreePBX',
                'version' => '1.0.0',
                'controllers' => ['FreepbxController'],
                'models' => [],
                'services' => [],
                'route_prefix' => '/api/freepbx',
                'permissions' => [],
                'config_files' => ['freepbx.php'],
                'migrations' => [],
            ],
        ];

        return $definitions;
    }

    private function buildExtension(string $id, array $module): array
    {
        $checks = $this->runDiagnostics($id, $module);
        $health = !in_array(false, array_values($checks)) ? 'healthy' : 'failed';
        $status = $health === 'healthy' ? 'enabled' : 'disabled';

        return [
            'id' => $id,
            'name' => $module['name_en'] ?? $id,
            'name_ar' => $module['name'] ?? $id,
            'display_name' => $module['name'] ?? $id,
            'description' => $module['description'] ?? '',
            'version' => $module['version'] ?? '1.0.0',
            'status' => $status,
            'source' => 'internal',
            'type' => 'module',
            'route' => $module['route_prefix'] ?? null,
            'permissions' => $module['permissions'] ?? [],
            'dependencies' => [],
            'health' => $health,
            'health_checks' => $checks,
            'errors' => array_filter(array_map(function ($ok, $key) {
                return $ok ? null : "Health check failed: {$key}";
            }, $checks, array_keys($checks))),
            'components' => [
                'controllers' => $module['controllers'] ?? [],
                'models' => $module['models'] ?? [],
                'services' => $module['services'] ?? [],
                'migrations' => $module['migrations'] ?? [],
                'config_files' => $module['config_files'] ?? [],
            ],
        ];
    }

    private function runDiagnostics(string $id, array $module): array
    {
        $checks = [];

        // 1. Can it be loaded? (controllers exist)
        $controllerOk = true;
        foreach ($module['controllers'] as $ctrl) {
            $path = base_path("app/Http/Controllers/{$ctrl}.php");
            if (!file_exists($path)) {
                $controllerOk = false;
                break;
            }
        }
        $checks['controllers_loadable'] = $controllerOk;

        // 2. Are routes registered?
        $routeOk = !empty($module['route_prefix']);
        if ($routeOk) {
            try {
                $routeCollection = app('router')->getRoutes();
                $prefix = ltrim($module['route_prefix'], '/');
                $routeOk = collect($routeCollection->getRoutes())->some(
                    fn($r) => str_starts_with($r->getUri(), $prefix)
                );
            } catch (\Exception $e) {
                $routeOk = false;
            }
        }
        $checks['routes_registered'] = $routeOk;

        // 3. Are APIs available?
        $checks['apis_available'] = $routeOk;

        // 4. Are required migrations applied?
        $migrationOk = true;
        $migratedTables = [];
        try {
            $migratedTables = \Illuminate\Support\Facades\Schema::getColumnListing('migrations');
            // Check via migration files existence + DB status
            foreach ($module['migrations'] as $migrationPrefix) {
                $migrationFiles = glob(base_path("database/migrations/{$migrationPrefix}*"));
                if (empty($migrationFiles)) {
                    // Migration file doesn't exist — might be fine if table exists
                    continue;
                }
            }
        } catch (\Exception $e) {
            $migrationOk = false;
        }
        $checks['migrations_applied'] = $migrationOk;

        // 5. Are permissions registered?
        $permOk = true;
        if (!empty($module['permissions'])) {
            try {
                $permOk = class_exists(\Spatie\Permission\Models\Permission::class);
            } catch (\Exception $e) {
                $permOk = false;
            }
        }
        $checks['permissions_registered'] = $permOk;

        // 6. Are configuration files present?
        $configOk = true;
        foreach ($module['config_files'] as $cfg) {
            if (!file_exists(config_path("{$cfg}.php"))) {
                $configOk = false;
                break;
            }
        }
        $checks['config_present'] = $configOk;

        // 7. Are required services initialized?
        $serviceOk = true;
        foreach ($module['services'] as $svc) {
            $path = base_path("app/{$svc}");
            if (!file_exists($path) && !file_exists($path . '.php')) {
                $serviceOk = false;
                break;
            }
        }
        $checks['services_initialized'] = $serviceOk;

        return $checks;
    }

    private function detectPackages(): array
    {
        $composerPath = base_path('composer.json');
        if (!file_exists($composerPath)) return [];

        $composer = json_decode(file_get_contents($composerPath), true);
        $require = $composer['require'] ?? [];
        $requireDev = $composer['require-dev'] ?? [];

        $packages = [
            'laravel/sanctum' => [
                'name' => 'Laravel Sanctum',
                'name_ar' => 'سانكتوم',
                'description' => 'مصادقة API للتطبيقات والـ SPA',
                'type' => 'package',
                'config_file' => 'sanctum.php',
                'route_prefix' => null,
                'permissions' => [],
                'is_dev' => false,
            ],
            'spatie/laravel-permission' => [
                'name' => 'Spatie Permission',
                'name_ar' => 'صلاحيات سباتي',
                'description' => 'نظام الأدوار والصلاحيات RBAC',
                'type' => 'package',
                'config_file' => 'permission.php',
                'route_prefix' => null,
                'permissions' => [],
                'is_dev' => false,
            ],
            'laravel/telescope' => [
                'name' => 'Laravel Telescope',
                'name_ar' => 'تيليسكوب',
                'description' => 'لوحة التصحيح والمراقبة',
                'type' => 'package',
                'config_file' => 'telescope.php',
                'route_prefix' => '/telescope',
                'permissions' => [],
                'is_dev' => true,
            ],
            'mpdf/mpdf' => [
                'name' => 'mPDF',
                'name_ar' => 'مكتبة PDF',
                'description' => 'توليد ملفات PDF',
                'type' => 'package',
                'config_file' => null,
                'route_prefix' => null,
                'permissions' => [],
                'is_dev' => false,
            ],
            'maatwebsite/excel' => [
                'name' => 'Maatwebsite Excel',
                'name_ar' => 'مكتبة Excel',
                'description' => 'استيراد وتصدير ملفات Excel',
                'type' => 'package',
                'config_file' => null,
                'route_prefix' => null,
                'permissions' => [],
                'is_dev' => false,
            ],
        ];

        $result = [];
        $allRequire = array_merge($require, $requireDev);

        foreach ($packages as $pkgName => $pkgConfig) {
            $installed = isset($allRequire[$pkgName]);
            $version = $allRequire[$pkgName] ?? 'unknown';

            $configPresent = $pkgConfig['config_file']
                ? file_exists(config_path("{$pkgConfig['config_file']}.php"))
                : null;

            $checks = [
                'installed' => $installed,
                'config_present' => $configPresent,
                'route_registered' => $pkgConfig['route_prefix'] ? true : null,
            ];

            $health = $installed ? 'healthy' : 'missing';

            $result[] = [
                'id' => str_replace('/', '.', $pkgName),
                'name' => $pkgConfig['name'],
                'name_ar' => $pkgConfig['name_ar'],
                'display_name' => $pkgConfig['name'],
                'description' => $pkgConfig['description'],
                'version' => is_string($version) ? preg_replace('/[^0-9.]/', '', $version) : '1.0.0',
                'status' => $installed ? 'enabled' : 'missing',
                'source' => 'composer',
                'type' => $pkgConfig['type'],
                'route' => $pkgConfig['route_prefix'],
                'permissions' => $pkgConfig['permissions'],
                'dependencies' => [],
                'health' => $health,
                'health_checks' => $checks,
                'errors' => $installed ? [] : ["Package {$pkgName} is not installed"],
                'components' => [
                    'controllers' => [],
                    'models' => [],
                    'services' => [],
                    'migrations' => [],
                    'config_files' => $pkgConfig['config_file'] ? [$pkgConfig['config_file']] : [],
                ],
            ];
        }

        return $result;
    }

    private function buildPackageExtension(array $pkg): array
    {
        return $pkg;
    }
}
