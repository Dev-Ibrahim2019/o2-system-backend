<?php
// routes/api.php â€” ط§ظ„ظ†ط³ط®ط© ط§ظ„ظƒط§ظ…ظ„ط©

use App\Http\Controllers\Api\Accounting\AccountController;
use App\Http\Controllers\Api\Accounting\CostCenterController;
use App\Http\Controllers\Api\Accounting\TransactionController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\FreepbxController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\FinancialTransactionController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\JobTitleController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoiceDetailsController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductionTicketController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\TableController;
use App\Http\Controllers\Auth\AuthController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// â”€â”€ Public routes â”€â”€
Route::get('branches', [BranchController::class, 'index']);
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::get('/freepbx/test', [FreepbxController::class, 'test']);

Route::prefix('customer')->group(function () {
    Route::get('table/{qrCode}', [\App\Http\Controllers\Api\CustomerPortalController::class, 'lookupByQrCode']);
    Route::get('table/{qrCode}/active-order', [\App\Http\Controllers\Api\CustomerPortalController::class, 'activeOrder']);
    Route::get('menu/{branchId}', [\App\Http\Controllers\Api\CustomerPortalController::class, 'menu']);
    Route::post('orders', [\App\Http\Controllers\Api\CustomerPortalController::class, 'addSubOrder']);
    Route::post('add-sub-order', [\App\Http\Controllers\Api\CustomerPortalController::class, 'addSubOrder']);
    Route::post('call-waiter', [\App\Http\Controllers\Api\CustomerPortalController::class, 'callWaiter']);
    Route::post('request-bill', [\App\Http\Controllers\Api\CustomerPortalController::class, 'requestBill']);
});

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', fn(Request $r) => response()->json(['user' => $r->user()]));

    // ط§ظ„ظ…ظ†ظٹظˆ â€” ظ…ط­ظ…ظٹ ظˆظٹظڈظپظ„طھط± طھظ„ظ‚ط§ط¦ظٹط§ظ‹ ط­ط³ط¨ ظپط±ط¹ ط§ظ„ظ…ط³طھط®ط¯ظ…
    Route::get('menu', [MenuController::class, 'index']);

    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    //  ظ…ط³ط§ط±ط§طھ ط§ظ„ظƒط§ط´ظٹط± / POS â€” ظ…ط­ظ…ظٹط© ط¨ظ€ CheckPosNetwork
    //  طھظ…ظ†ط¹ ط§ط³طھط®ط¯ط§ظ… ظ‡ط°ظ‡ ط§ظ„ظ…ط³ط§ط±ط§طھ ظ…ظ† ط®ط§ط±ط¬ ط´ط¨ظƒط© ط§ظ„ظپط±ط¹
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    Route::middleware('check.pos.network')->group(function () {

        // â”€â”€ Orders â”€â”€
        Route::post('orders/{order}/void', [OrderController::class, 'void']);
        Route::apiResource('orders', OrderController::class)->except(['destroy']);
        Route::post('orders/{order}/items', [OrderController::class, 'addItem']);
        Route::delete('orders/{order}/items/{orderItem}', [OrderController::class, 'removeItem']);
        Route::post('orders/{order}/confirm', [OrderController::class, 'confirm']);
        Route::post('orders/{order}/serve', [OrderController::class, 'serve']);
    Route::post('orders/{order}/defer', [OrderController::class, 'deferOrder']);
    Route::post('orders/{order}/transfer', [OrderController::class, 'transfer']);
        Route::get('orders/{order}/journal-entry', [OrderController::class, 'journalEntry']);
        Route::get('orders/{order}/print-sections', [OrderController::class, 'printSections']);
        Route::post('orders/{order}/print-invoice', [OrderController::class, 'printInvoice']);
        Route::post('orders/{order}/print-tickets', [OrderController::class, 'printTickets']);
        Route::post('orders/{order}/print-order', [OrderController::class, 'printOrder']);
        Route::post('orders/{order}/direct-print', [OrderController::class, 'directPrint']);
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);

        Route::get('production-tickets', [ProductionTicketController::class, 'index']);
        Route::post('production-tickets/{ticket}/start', [ProductionTicketController::class, 'startPreparing']);
        Route::post('production-tickets/{ticket}/ready', [ProductionTicketController::class, 'markReady']);
        Route::post('production-tickets/{ticket}/served', [ProductionTicketController::class, 'markServed']);

        Route::post('orders/{order}/invoice', [InvoiceController::class, 'createFromOrder']);
        Route::post('orders/{order}/close', [InvoiceController::class, 'createFromOrder']);
        Route::get('invoices', [InvoiceController::class, 'index']);
        Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'addPayment']);
        Route::get('invoices/{invoice}/journal-entry', [InvoiceController::class, 'journalEntry']);

        // ── Settlement & Payment Routing ──
        Route::post('orders/{order}/settle', [\App\Http\Controllers\Api\SettleController::class, 'settle']);
        Route::get('orders/{order}/settlement', [\App\Http\Controllers\Api\SettleController::class, 'show']);
        Route::apiResource('payment-methods', \App\Http\Controllers\Api\PaymentMethodController::class);

        // ── Shifts / الورديات ──
        Route::get('shifts', [ShiftController::class, 'index']);
        Route::get('shifts/current', [ShiftController::class, 'current']);
        Route::post('shifts/rollover', [ShiftController::class, 'rollover']);

        // ── Order Timeline / سجل الطلب الزمني ──
        Route::get('orders/{order}/timeline', [\App\Http\Controllers\Api\OrderTimelineController::class, 'timeline']);

    }); // نهاية مسار POS المحمي بـ CheckPosNetwork

    // ── Tables Management (موحدة للكاشير/الضيافة/المحاسب/المدير) ──
    Route::get('tables', [\App\Http\Controllers\Api\TableOperationsController::class, 'index']);
    Route::get('tables/{table}', [\App\Http\Controllers\Api\TableOperationsController::class, 'show']);
    Route::post('tables/{table}/seat', [\App\Http\Controllers\Api\TableOperationsController::class, 'seat']);
    Route::put('tables/{table}/status', [\App\Http\Controllers\Api\TableOperationsController::class, 'updateStatus']);
    Route::post('tables/{table}/free', [\App\Http\Controllers\Api\TableOperationsController::class, 'free']);
    Route::post('tables/transfer', [\App\Http\Controllers\Api\TableOperationsController::class, 'transfer']);
    Route::post('tables/merge', [\App\Http\Controllers\Api\TableOperationsController::class, 'merge']);
    Route::post('tables/{table}/unmerge', [\App\Http\Controllers\Api\TableOperationsController::class, 'unmerge']);
    Route::post('tables/{table}/defer-all', [\App\Http\Controllers\Api\TableOperationsController::class, 'deferAll']);

    // ── إدارة المستخدمين ──
    Route::get('users', [UserController::class, 'index'])->middleware('permission:manage-users');
    Route::post('users', [UserController::class, 'store'])->middleware('permission:manage-users');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('permission:manage-users');
    Route::get('roles', [UserController::class, 'roles'])->middleware('permission:manage-users');
    Route::put('users/{user}/role', [UserController::class, 'updateRole'])->middleware('permission:manage-users');
    Route::post('users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->middleware('permission:manage-users');

    // â”€â”€ ط¥ط¯ط§ط±ط© ط§ظ„ط£ط¯ظˆط§ط± ظˆط§ظ„طµظ„ط§ط­ظٹط§طھ â”€â”€
    Route::get('roles-list', [RoleController::class, 'index'])->middleware('permission:manage-users');
    Route::get('permissions-list', [RoleController::class, 'getAllPermissions'])->middleware('permission:manage-users');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:manage-users');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:manage-users');
    Route::put('roles/{role}/permissions', [RoleController::class, 'updatePermissions'])->middleware('permission:manage-users');

    // â”€â”€ Branches â”€â”€
    Route::get('branches/{branch}', [BranchController::class, 'show'])->middleware('permission:manage-branches');
    Route::post('branches', [BranchController::class, 'store'])->middleware('permission:manage-branches');
    Route::put('branches/{branch}', [BranchController::class, 'update'])->middleware('permission:manage-branches');
    Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('permission:manage-branches');
    Route::get('branches/{branch}/menu', [BranchController::class, 'menu'])->middleware('permission:manage-branches');

    // â”€â”€ Items â”€â”€
    Route::post('items/upload-image', [ItemController::class, 'uploadImage']);
    Route::apiResource('items', ItemController::class);
    // ── Job Titles / المسميات الوظيفية ──
    // القراءة متاحة لكل مستخدم مسجل لأن قوائم الموظفين ونماذج الطلبات تعتمد عليها.
    Route::get('job-titles', [JobTitleController::class, 'index']);
    Route::get('job-titles/{jobTitle}', [JobTitleController::class, 'show']);
    Route::middleware('permission:manage-employees')->group(function () {
        Route::post('job-titles', [JobTitleController::class, 'store']);
        Route::put('job-titles/{jobTitle}', [JobTitleController::class, 'update']);
        Route::patch('job-titles/{jobTitle}', [JobTitleController::class, 'update']);
        Route::delete('job-titles/{jobTitle}', [JobTitleController::class, 'destroy']);
    });

    // ── Employee HR + Financial Module / الموظفون ──
    // جميع العمليات المالية/الدوام تحتاج صلاحية الموارد البشرية أو المحاسبة.
    Route::prefix('employees')->middleware('permission:manage-employees|manage-accounting')->group(function () {
        Route::get('/financial-batch', [\App\Http\Controllers\Api\EmployeeFinancialController::class, 'financialBatch']);
        Route::get('/dashboard', [\App\Http\Controllers\Api\EmployeeFinancialController::class, 'dashboard']);
        Route::get('/analytics', [\App\Http\Controllers\Api\EmployeeFinancialController::class, 'analytics']);

        Route::post('/{employee}/advance', [\App\Http\Controllers\Api\EmployeeFinancialController::class, 'recordAdvance']);
        Route::post('/{employee}/advance-repayment', [\App\Http\Controllers\Api\EmployeeFinancialController::class, 'recordAdvanceRepayment']);
        Route::post('/{employee}/salary-accrual', [\App\Http\Controllers\Api\EmployeeFinancialController::class, 'accrualSalary']);
        Route::post('/{employee}/salary-payment', [\App\Http\Controllers\Api\EmployeeFinancialController::class, 'paySalary']);
        Route::get('/{employee}/financial-summary', [\App\Http\Controllers\Api\EmployeeFinancialController::class, 'financialSummary']);
        Route::get('/{employee}/account-statement', [\App\Http\Controllers\Api\EmployeeFinancialController::class, 'accountStatement']);
        Route::get('/{employee}/account-statement/export', [\App\Http\Controllers\Api\EmployeeFinancialController::class, 'accountStatementExport']);
        Route::get('/{employee}/account-statement/pdf', [\App\Http\Controllers\Api\EmployeeFinancialController::class, 'accountStatementPdf']);
        Route::post('/{employee}/loan', [\App\Http\Controllers\Api\EmployeeFinancialController::class, 'recordLoan']);
        Route::post('/{employee}/loan-repayment', [\App\Http\Controllers\Api\EmployeeFinancialController::class, 'recordLoanRepayment']);
        Route::get('/{employee}/loans', [\App\Http\Controllers\Api\EmployeeFinancialController::class, 'getLoans']);
        Route::post('/{employee}/settlement', [\App\Http\Controllers\Api\EmployeeFinancialController::class, 'recordSettlement']);

        // الدوام الأسبوعي
        Route::get('/{employee}/work-schedule', [\App\Http\Controllers\Api\EmployeeWorkScheduleController::class, 'index']);
        Route::put('/{employee}/work-schedule', [\App\Http\Controllers\Api\EmployeeWorkScheduleController::class, 'upsert']);

        // الحضور والساعات
        Route::get('/{employee}/attendance', [\App\Http\Controllers\Api\EmployeeAttendanceController::class, 'index']);
        Route::post('/{employee}/attendance', [\App\Http\Controllers\Api\EmployeeAttendanceController::class, 'store']);
        Route::delete('/{employee}/attendance/{attendance}', [\App\Http\Controllers\Api\EmployeeAttendanceController::class, 'destroy']);

        // السحوبات — سجل مستقل مع أثر محاسبي على رصيد السلف
        Route::get('/{employee}/withdrawals', [\App\Http\Controllers\Api\EmployeeWithdrawalController::class, 'index']);
        Route::post('/{employee}/withdrawals', [\App\Http\Controllers\Api\EmployeeWithdrawalController::class, 'store']);
        Route::delete('/{employee}/withdrawals/{withdrawal}', [\App\Http\Controllers\Api\EmployeeWithdrawalController::class, 'destroy']);

        // سجل الرواتب الشهري + المعالجة الموحدة
        Route::get('/{employee}/payrolls', [\App\Http\Controllers\Api\EmployeePayrollController::class, 'index']);
        Route::post('/{employee}/payrolls/process', [\App\Http\Controllers\Api\EmployeePayrollController::class, 'process']);
    });

    // قراءة بيانات الموظفين مطلوبة في أكثر من وحدة تشغيلية. التعديل محمي بصلاحية الموظفين.
    Route::get('employees', [EmployeeController::class, 'index']);
    Route::get('employees/{employee}', [EmployeeController::class, 'show']);
    Route::middleware('permission:manage-employees')->group(function () {
        Route::post('employees', [EmployeeController::class, 'store']);
        Route::put('employees/{employee}', [EmployeeController::class, 'update']);
        Route::patch('employees/{employee}', [EmployeeController::class, 'update']);
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy']);
    });

    // â”€â”€ Orders â”€â”€
    Route::post('orders/batch-invoice-ids', [InvoiceDetailsController::class, 'batchInvoiceIds']); // MUST be before apiResource
    Route::post('orders/{order}/void', [OrderController::class, 'void']);
    Route::apiResource('orders', OrderController::class)->except(['destroy']);
    Route::post('orders/{order}/items', [OrderController::class, 'addItem']);
    Route::delete('orders/{order}/items/{orderItem}', [OrderController::class, 'removeItem']);
    Route::post('orders/{order}/confirm', [OrderController::class, 'confirm']);
    Route::post('orders/{order}/serve', [OrderController::class, 'serve']);
    Route::post('orders/{order}/defer', [OrderController::class, 'deferOrder']);
    Route::post('orders/{order}/transfer', [OrderController::class, 'transfer']);
    Route::get('orders/{order}/journal-entry', [OrderController::class, 'journalEntry']);
    Route::get('orders/{order}/print-sections', [OrderController::class, 'printSections']);
    Route::post('orders/{order}/print-invoice', [OrderController::class, 'printInvoice']);
    Route::post('orders/{order}/print-tickets', [OrderController::class, 'printTickets']);
    Route::post('orders/{order}/direct-print', [OrderController::class, 'directPrint']);
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);
    Route::post('orders/{order}/sync-pricing', [OrderController::class, 'syncPricing']);
    Route::get('orders/{order}/invoice-id', [InvoiceDetailsController::class, 'getInvoiceIdFromOrder']);

    Route::get('production-tickets', [ProductionTicketController::class, 'index']);
    Route::post('production-tickets/{ticket}/start', [ProductionTicketController::class, 'startPreparing']);
    Route::post('production-tickets/{ticket}/ready', [ProductionTicketController::class, 'markReady']);
    Route::post('production-tickets/{ticket}/served', [ProductionTicketController::class, 'markServed']);

    Route::post('orders/{order}/invoice', [InvoiceController::class, 'createFromOrder']);
    Route::post('orders/{order}/close', [InvoiceController::class, 'createFromOrder']); // alias for cashier close action
    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'addPayment']);
    Route::get('invoices/{invoice}/journal-entry', [InvoiceController::class, 'journalEntry']);

    // â”€â”€ Invoice Details Drawer (lazy-load endpoints) â”€â”€
    Route::prefix('invoices/{invoice}')->group(function () {
        Route::get('details', [InvoiceDetailsController::class, 'details']);
        Route::get('products', [InvoiceDetailsController::class, 'products']);
        Route::get('payments', [InvoiceDetailsController::class, 'payments']);
        Route::get('accounting', [InvoiceDetailsController::class, 'accounting']);
        Route::get('discounts', [InvoiceDetailsController::class, 'discounts']);
        Route::get('inventory', [InvoiceDetailsController::class, 'inventory']);
        Route::get('timeline', [InvoiceDetailsController::class, 'timeline']);
        Route::get('attachments', [InvoiceDetailsController::class, 'attachments']);
        Route::get('notes', [InvoiceDetailsController::class, 'notes']);
    });

    // ── Financial Invoices ──
    Route::prefix('financial/invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'financialIndex']);
        Route::get('/stats', [InvoiceController::class, 'financialStats']);
        Route::get('/{invoice}', [InvoiceController::class, 'financialShow']);
        Route::post('/', [InvoiceController::class, 'financialStore']);
        Route::put('/{invoice}', [InvoiceController::class, 'financialUpdate']);
        Route::delete('/{invoice}', [InvoiceController::class, 'financialDestroy']);
        Route::post('/{invoice}/approve', [InvoiceController::class, 'approve']);
        Route::post('/{invoice}/void', [InvoiceController::class, 'voidFinancial']);
    });

    // â”€â”€ Sales Invoices (Full Module) â”€â”€
    Route::prefix('sales-invoices')->group(function () {
        // Stats must be before {invoice} to avoid route conflict
        Route::get('/stats', [\App\Http\Controllers\Api\SalesInvoiceController::class, 'stats']);
        Route::get('/overdue', [\App\Http\Controllers\Api\SalesInvoiceController::class, 'overdue']);
        Route::get('/pos-invoices', [\App\Http\Controllers\Api\SalesInvoiceController::class, 'posInvoices']);

        // Excel Import
        Route::post('/import', [\App\Http\Controllers\Api\SalesInvoiceController::class, 'import']);

        // POS Sync
        Route::post('/pos-sync/end-of-day', [\App\Http\Controllers\Api\SalesInvoiceController::class, 'posSyncEndOfDay']);
        Route::post('/pos-sync/batch', [\App\Http\Controllers\Api\SalesInvoiceController::class, 'posSyncBatch']);
        Route::post('/pos-sync/single/{posInvoiceId}', [\App\Http\Controllers\Api\SalesInvoiceController::class, 'posSyncSingle']);

        // CRUD
        Route::get('/', [\App\Http\Controllers\Api\SalesInvoiceController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\SalesInvoiceController::class, 'store']);
        Route::get('/{invoice}', [\App\Http\Controllers\Api\SalesInvoiceController::class, 'show']);
        Route::put('/{invoice}', [\App\Http\Controllers\Api\SalesInvoiceController::class, 'update']);
        Route::delete('/{invoice}', [\App\Http\Controllers\Api\SalesInvoiceController::class, 'destroy']);

        // Workflow
        Route::post('/{invoice}/approve', [\App\Http\Controllers\Api\SalesInvoiceController::class, 'approve']);
        Route::post('/{invoice}/cancel', [\App\Http\Controllers\Api\SalesInvoiceController::class, 'cancel']);
        Route::post('/{invoice}/payments', [\App\Http\Controllers\Api\SalesInvoiceController::class, 'storePayment']);
        Route::post('/bulk-approve', [\App\Http\Controllers\Api\SalesInvoiceController::class, 'bulkApprove']);
        Route::post('/bulk-post', [\App\Http\Controllers\Api\SalesInvoiceController::class, 'bulkPost']);
        Route::get('/group', [\App\Http\Controllers\Api\SalesInvoiceController::class, 'group']);
    });

    Route::prefix('accounting')->group(function () {
        Route::apiResource('accounts', AccountController::class);
        Route::get('accounts/{account}/ledger', [AccountController::class, 'ledger']);
        Route::get('transactions/by-source', [TransactionController::class, 'bySource']);
        Route::apiResource('transactions', TransactionController::class);
        Route::post('transactions/{transaction}/post', [TransactionController::class, 'post']);
        Route::post('transactions/{transaction}/cancel', [TransactionController::class, 'cancel']);
        Route::apiResource('cost-centers', CostCenterController::class);
    });

    // ── Fiscal Years / السنوات المالية ──
    Route::get('fiscal-years', [\App\Http\Controllers\Api\FiscalYearController::class, 'index']);
    Route::get('fiscal-years/active', [\App\Http\Controllers\Api\FiscalYearController::class, 'active']);
    Route::post('fiscal-years', [\App\Http\Controllers\Api\FiscalYearController::class, 'store']);
    Route::post('fiscal-years/{fiscalYear}/close', [\App\Http\Controllers\Api\FiscalYearController::class, 'close']);
});

// â”€â”€ POS Registers (Admin) â”€â”€
Route::middleware('auth:sanctum')->prefix('admin/pos-registers')->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\PosRegisterController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Admin\PosRegisterController::class, 'store']);
    Route::post('{id}/generate-token', [\App\Http\Controllers\Admin\PosRegisterController::class, 'generateActivationToken']);
    Route::post('{id}/revoke', [\App\Http\Controllers\Admin\PosRegisterController::class, 'revokeDevice']);
    // â”€â”€ Discount Management â”€â”€
    // All endpoints accessible to all authenticated users
    Route::prefix('discounts')->group(function () {
        Route::get('/calculate', [\App\Http\Controllers\Api\DiscountController::class, 'calculate']);
        Route::post('/calculate', [\App\Http\Controllers\Api\DiscountController::class, 'calculate']);
        Route::post('/calculate-cart', [\App\Http\Controllers\Api\DiscountController::class, 'calculateCart']);
        Route::get('/dashboard', [\App\Http\Controllers\Api\DiscountController::class, 'dashboard']);
        Route::get('/entities', [\App\Http\Controllers\Api\DiscountController::class, 'entities']);
        Route::post('/debug', [\App\Http\Controllers\Api\DiscountController::class, 'debug']);
        Route::post('/validate-target', [\App\Http\Controllers\Api\DiscountController::class, 'validateTarget']);
        Route::get('/', [\App\Http\Controllers\Api\DiscountController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\DiscountController::class, 'store']);
        Route::get('/{discount}', [\App\Http\Controllers\Api\DiscountController::class, 'show']);
        Route::put('/{discount}', [\App\Http\Controllers\Api\DiscountController::class, 'update']);
        Route::delete('/{discount}', [\App\Http\Controllers\Api\DiscountController::class, 'destroy']);
    });
});

// â”€â”€ Departments â”€â”€
Route::middleware('auth:sanctum')->prefix('departments')->group(function () {
    Route::get('/', [DepartmentController::class, 'index']);
    Route::get('/tree', [DepartmentController::class, 'tree']);
    Route::post('/', [DepartmentController::class, 'store']);
    Route::get('/{department}', [DepartmentController::class, 'show']);
    Route::put('/{department}', [DepartmentController::class, 'update']);
    Route::delete('/{department}', [DepartmentController::class, 'destroy']);
});

// â”€â”€ Suppliers â”€â”€
Route::prefix("suppliers")->group(function () {
    Route::get("/", [\App\Http\Controllers\Api\SupplierFinancialController::class, "index"]);
    Route::post("/", [\App\Http\Controllers\Api\SupplierFinancialController::class, "store"]);
    Route::get("/{supplier}", [\App\Http\Controllers\Api\SupplierFinancialController::class, "show"]);
    Route::put("/{supplier}", [\App\Http\Controllers\Api\SupplierFinancialController::class, "update"]);
    Route::delete("/{supplier}", [\App\Http\Controllers\Api\SupplierFinancialController::class, "destroy"]);
    Route::post("/{supplier}/bill", [\App\Http\Controllers\Api\SupplierFinancialController::class, "recordBill"]);
    Route::post("/{supplier}/payment", [\App\Http\Controllers\Api\SupplierFinancialController::class, "recordPayment"]);
    Route::post("/{supplier}/credit-note", [\App\Http\Controllers\Api\SupplierFinancialController::class, "recordCreditNote"]);
    Route::post("/{supplier}/debit-note", [\App\Http\Controllers\Api\SupplierFinancialController::class, "recordDebitNote"]);
    Route::get("/{supplier}/statement", [\App\Http\Controllers\Api\SupplierFinancialController::class, "statement"]);
    Route::get("/{supplier}/statement/export", [\App\Http\Controllers\Api\SupplierFinancialController::class, "statementExport"]);
    Route::get("/{supplier}/statement/pdf", [\App\Http\Controllers\Api\SupplierFinancialController::class, "statementPdf"]);
    Route::get("/{supplier}/aging", [\App\Http\Controllers\Api\SupplierFinancialController::class, "aging"]);
    Route::get("/{supplier}/monthly-payments", [\App\Http\Controllers\Api\SupplierFinancialController::class, "monthlyPayments"]);
    Route::get("/{supplier}/transactions", [\App\Http\Controllers\Api\SupplierFinancialController::class, "transactions"]);
    Route::get("/aging-report", [\App\Http\Controllers\Api\SupplierFinancialController::class, "agingReport"]);
});

// â”€â”€ Customers â”€â”€
Route::prefix("customers")->group(function () {
    Route::get("/", [\App\Http\Controllers\Api\CustomerFinancialController::class, "index"]);
    Route::post("/", [\App\Http\Controllers\Api\CustomerFinancialController::class, "store"]);
    Route::get("/{customer}", [\App\Http\Controllers\Api\CustomerFinancialController::class, "show"]);
    Route::put("/{customer}", [\App\Http\Controllers\Api\CustomerFinancialController::class, "update"]);
    Route::delete("/{customer}", [\App\Http\Controllers\Api\CustomerFinancialController::class, "destroy"]);
    Route::post("/{customer}/invoice", [\App\Http\Controllers\Api\CustomerFinancialController::class, "recordInvoice"]);
    Route::post("/{customer}/receipt", [\App\Http\Controllers\Api\CustomerFinancialController::class, "recordReceipt"]);
    Route::post("/{customer}/payment", [\App\Http\Controllers\Api\CustomerFinancialController::class, "recordReceipt"]);
    Route::post("/{customer}/credit-note", [\App\Http\Controllers\Api\CustomerFinancialController::class, "recordCreditNote"]);
    Route::post("/{customer}/debit-note", [\App\Http\Controllers\Api\CustomerFinancialController::class, "recordDebitNote"]);
    Route::get("/{customer}/statement", [\App\Http\Controllers\Api\CustomerFinancialController::class, "statement"]);
    Route::get("/{customer}/statement/export", [\App\Http\Controllers\Api\CustomerFinancialController::class, "statementExport"]);
    Route::get("/{customer}/statement/pdf", [\App\Http\Controllers\Api\CustomerFinancialController::class, "statementPdf"]);
    Route::get("/{customer}/aging", [\App\Http\Controllers\Api\CustomerFinancialController::class, "aging"]);
    Route::get("/{customer}/analytics", [\App\Http\Controllers\Api\CustomerFinancialController::class, "analytics"]);
    Route::get("/aging-report", [\App\Http\Controllers\Api\CustomerFinancialController::class, "agingReport"]);
    Route::get("/collection-report", [\App\Http\Controllers\Api\CustomerFinancialController::class, "collectionReport"]);
});

Route::post('pos/activate', [\App\Http\Controllers\Admin\PosRegisterController::class, 'activate']);

Route::middleware('auth:sanctum')->post('pos/check-status', [\App\Http\Controllers\Admin\PosRegisterController::class, 'checkStatus']);

// ── Printers & Print Routes (Admin) ──
Route::middleware('auth:sanctum')->prefix('admin/printers')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\PrinterController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Api\PrinterController::class, 'store']);
    Route::get('/{id}', [\App\Http\Controllers\Api\PrinterController::class, 'show']);
    Route::put('/{id}', [\App\Http\Controllers\Api\PrinterController::class, 'update']);
    Route::delete('/{id}', [\App\Http\Controllers\Api\PrinterController::class, 'destroy']);
    Route::post('/{id}/test-connection', [\App\Http\Controllers\Api\PrinterController::class, 'testConnection']);
    Route::post('/{id}/test-print', [\App\Http\Controllers\Api\PrinterController::class, 'testPrint']);
    Route::get('/{id}/routes', [\App\Http\Controllers\Api\PrinterController::class, 'routes']);
});

Route::middleware('auth:sanctum')->prefix('admin/print-routes')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\PrintRouteController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Api\PrintRouteController::class, 'store']);
    Route::put('/{printRoute}', [\App\Http\Controllers\Api\PrintRouteController::class, 'update']);
    Route::delete('/{printRoute}', [\App\Http\Controllers\Api\PrintRouteController::class, 'destroy']);
});

// â”€â”€ Hospitality Devices (Admin) â”€â”€
Route::middleware('auth:sanctum')->prefix('admin/hospitality-devices')->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\HospitalityDeviceController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Admin\HospitalityDeviceController::class, 'store']);
    Route::post('{id}/generate-token', [\App\Http\Controllers\Admin\HospitalityDeviceController::class, 'generateActivationToken']);
    Route::post('{id}/revoke', [\App\Http\Controllers\Admin\HospitalityDeviceController::class, 'revokeDevice']);
});

// â”€â”€ Hospitality Activation (Public) â”€â”€
Route::post('hospitality/activate', [\App\Http\Controllers\Admin\HospitalityDeviceController::class, 'activate']);
Route::middleware('auth:sanctum')->post('hospitality/check-status', [\App\Http\Controllers\Admin\HospitalityDeviceController::class, 'checkStatus']);

// â”€â”€ Dining Zones (Admin) â”€â”€
Route::middleware('auth:sanctum')->prefix('admin/dining-zones')->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\DiningZoneController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Admin\DiningZoneController::class, 'store']);
    Route::get('/{id}', [\App\Http\Controllers\Admin\DiningZoneController::class, 'show']);
    Route::put('/{id}', [\App\Http\Controllers\Admin\DiningZoneController::class, 'update']);
    Route::delete('/{id}', [\App\Http\Controllers\Admin\DiningZoneController::class, 'destroy']);
    Route::post('/{zoneId}/tables', [\App\Http\Controllers\Admin\DiningZoneController::class, 'addTable']);
    Route::delete('/{zoneId}/tables/{tableId}', [\App\Http\Controllers\Admin\DiningZoneController::class, 'destroyTable']);
    Route::put('/{zoneId}/tables/{tableId}/status', [\App\Http\Controllers\Admin\DiningZoneController::class, 'updateTableStatus']);
});

// â”€â”€ Dining Zones (POS / Hospitality) â”€â”€
Route::middleware('auth:sanctum')->prefix('dining-zones')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\DiningZoneController::class, 'index']);
    Route::get('/{id}', [\App\Http\Controllers\Api\DiningZoneController::class, 'show']);
});

// ── Quotes (عروض الأسعار) ──
Route::middleware('auth:sanctum')->prefix('quotes')->group(function () {
    Route::get('/stats', [\App\Http\Controllers\Api\QuoteController::class, 'stats']);
    Route::get('/', [\App\Http\Controllers\Api\QuoteController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Api\QuoteController::class, 'store']);
    Route::get('/{id}', [\App\Http\Controllers\Api\QuoteController::class, 'show']);
    Route::put('/{id}', [\App\Http\Controllers\Api\QuoteController::class, 'update']);
    Route::delete('/{id}', [\App\Http\Controllers\Api\QuoteController::class, 'destroy']);
    Route::post('/{id}/send', [\App\Http\Controllers\Api\QuoteController::class, 'send']);
    Route::post('/{id}/accept', [\App\Http\Controllers\Api\QuoteController::class, 'accept']);
    Route::post('/{id}/reject', [\App\Http\Controllers\Api\QuoteController::class, 'reject']);
    Route::post('/{id}/duplicate', [\App\Http\Controllers\Api\QuoteController::class, 'duplicate']);
    Route::post('/{id}/convert', [\App\Http\Controllers\Api\QuoteController::class, 'convertToInvoice']);
});

// ── Vouchers (سندات القبض والصرف) ──
Route::middleware('auth:sanctum')->prefix('vouchers')->group(function () {
    Route::get('/stats', [\App\Http\Controllers\Api\VoucherController::class, 'stats']);
    Route::get('/entity-invoices', [\App\Http\Controllers\Api\VoucherController::class, 'getEntityInvoices']);
    Route::get('/', [\App\Http\Controllers\Api\VoucherController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Api\VoucherController::class, 'store']);
    Route::get('/{id}', [\App\Http\Controllers\Api\VoucherController::class, 'show']);
    Route::put('/{id}', [\App\Http\Controllers\Api\VoucherController::class, 'update']);
    Route::delete('/{id}', [\App\Http\Controllers\Api\VoucherController::class, 'destroy']);
    Route::post('/{id}/activate', [\App\Http\Controllers\Api\VoucherController::class, 'activate']);
    Route::post('/{id}/cancel', [\App\Http\Controllers\Api\VoucherController::class, 'cancel']);
    Route::post('/{id}/approve', [\App\Http\Controllers\Api\VoucherController::class, 'approve']);
    Route::get('/{id}/entity-invoices', [\App\Http\Controllers\Api\VoucherController::class, 'entityInvoices']);
});

// ── Purchase Bills (فواتير المشتريات) ──
Route::middleware('auth:sanctum')->prefix('purchase-bills')->group(function () {
    Route::get('/stats', [\App\Http\Controllers\Api\PurchaseBillController::class, 'stats']);
    Route::post('/mark-overdue', [\App\Http\Controllers\Api\PurchaseBillController::class, 'markOverdue']);
    Route::get('/', [\App\Http\Controllers\Api\PurchaseBillController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Api\PurchaseBillController::class, 'store']);
    Route::get('/{purchaseBill}', [\App\Http\Controllers\Api\PurchaseBillController::class, 'show']);
    Route::put('/{purchaseBill}', [\App\Http\Controllers\Api\PurchaseBillController::class, 'update']);
    Route::delete('/{purchaseBill}', [\App\Http\Controllers\Api\PurchaseBillController::class, 'destroy']);
    Route::post('/{purchaseBill}/approve', [\App\Http\Controllers\Api\PurchaseBillController::class, 'approve']);
    Route::post('/{purchaseBill}/cancel', [\App\Http\Controllers\Api\PurchaseBillController::class, 'cancel']);
    Route::post('/{purchaseBill}/payments', [\App\Http\Controllers\Api\PurchaseBillController::class, 'recordPayment']);
});

// ── System Diagnostics ──
Route::middleware('auth:sanctum')->prefix('system')->group(function () {
    Route::get('/extensions', [\App\Http\Controllers\Api\SystemController::class, 'extensions']);
});

// ── PBX Extensions ──
Route::middleware('auth:sanctum')->prefix('pbx')->group(function () {
    Route::get('/extensions', [\App\Http\Controllers\Api\PbxExtensionController::class, 'index']);
    Route::get('/extensions/{extension}', [\App\Http\Controllers\Api\PbxExtensionController::class, 'testExtension']);
    Route::get('/recordings', [\App\Http\Controllers\Api\PbxRecordingController::class, 'index']);
    Route::get('/recordings/stats', [\App\Http\Controllers\Api\PbxRecordingController::class, 'stats']);
    Route::get('/recordings/play', [\App\Http\Controllers\Api\PbxRecordingController::class, 'play']);
    Route::get('/recordings/download', [\App\Http\Controllers\Api\PbxRecordingController::class, 'download']);
// ── Unified Tables API (POS, Hospitality, Accountant, Manager) ──
Route::middleware('auth:sanctum')->prefix('tables')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\TableOperationsController::class, 'index']);
    Route::get('/{table}', [\App\Http\Controllers\Api\TableOperationsController::class, 'show']);
    Route::post('/{table}/seat', [\App\Http\Controllers\Api\TableOperationsController::class, 'seat']);
    Route::put('/{table}/status', [\App\Http\Controllers\Api\TableOperationsController::class, 'updateStatus']);
    Route::post('/{table}/free', [\App\Http\Controllers\Api\TableOperationsController::class, 'free']);
    Route::post('/transfer', [\App\Http\Controllers\Api\TableOperationsController::class, 'transfer']);
    Route::post('/merge', [\App\Http\Controllers\Api\TableOperationsController::class, 'merge']);
    Route::post('/{table}/unmerge', [\App\Http\Controllers\Api\TableOperationsController::class, 'unmerge']);
});

Route::middleware('auth:sanctum')->post('pos/check-status', [\App\Http\Controllers\Admin\PosRegisterController::class, 'checkStatus']);
});
