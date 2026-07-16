<?php
// routes/api.php أ¢â‚¬â€‌ ط·آ§ط¸â€‍ط¸â€ ط·آ³ط·آ®ط·آ© ط·آ§ط¸â€‍ط¸ئ’ط·آ§ط¸â€¦ط¸â€‍ط·آ©

use App\Http\Controllers\Api\Accounting\AccountController;
use App\Http\Controllers\Api\Accounting\CostCenterController;
use App\Http\Controllers\Api\Accounting\TransactionController;
use App\Http\Controllers\Api\BranchController;
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
use App\Http\Controllers\Api\CallCenterController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// أ¢â€‌â‚¬أ¢â€‌â‚¬ Public routes أ¢â€‌â‚¬أ¢â€‌â‚¬
Route::get('branches', [BranchController::class, 'index']);
Route::post('/login', [AuthController::class, 'login'])->name('login');

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', fn(Request $r) => response()->json(['user' => $r->user()]));

    // ط·آ§ط¸â€‍ط¸â€¦ط¸â€ ط¸ظ¹ط¸ث† أ¢â‚¬â€‌ ط¸â€¦ط·آ­ط¸â€¦ط¸ظ¹ ط¸ث†ط¸ظ¹ط¸عˆط¸ظ¾ط¸â€‍ط·ع¾ط·آ± ط·ع¾ط¸â€‍ط¸â€ڑط·آ§ط·آ¦ط¸ظ¹ط·آ§ط¸â€¹ ط·آ­ط·آ³ط·آ¨ ط¸ظ¾ط·آ±ط·آ¹ ط·آ§ط¸â€‍ط¸â€¦ط·آ³ط·ع¾ط·آ®ط·آ¯ط¸â€¦
    Route::get('menu', [MenuController::class, 'index'])
        ->middleware('permission:view-menu|access-pos|access-pos-interface|manage-items');

    // أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯
    //  ط¸â€¦ط·آ³ط·آ§ط·آ±ط·آ§ط·ع¾ ط·آ§ط¸â€‍ط¸ئ’ط·آ§ط·آ´ط¸ظ¹ط·آ± / POS أ¢â‚¬â€‌ ط¸â€¦ط·آ­ط¸â€¦ط¸ظ¹ط·آ© ط·آ¨ط¸â‚¬ CheckPosNetwork
    //  ط·ع¾ط¸â€¦ط¸â€ ط·آ¹ ط·آ§ط·آ³ط·ع¾ط·آ®ط·آ¯ط·آ§ط¸â€¦ ط¸â€،ط·آ°ط¸â€، ط·آ§ط¸â€‍ط¸â€¦ط·آ³ط·آ§ط·آ±ط·آ§ط·ع¾ ط¸â€¦ط¸â€  ط·آ®ط·آ§ط·آ±ط·آ¬ ط·آ´ط·آ¨ط¸ئ’ط·آ© ط·آ§ط¸â€‍ط¸ظ¾ط·آ±ط·آ¹
    // أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯أ¢â€¢ع¯
    Route::middleware('check.pos.network')->group(function () {

        // أ¢â€‌â‚¬أ¢â€‌â‚¬ Orders أ¢â€‌â‚¬أ¢â€‌â‚¬
        Route::post('orders/{order}/void', [OrderController::class, 'void'])
            ->middleware('permission:manage-orders');
        Route::put('orders/{order}/confirm-payment', [OrderController::class, 'confirmPayment'])
            ->middleware('permission:add-payments|manage-payments|manage-orders');
        Route::put('orders/{order}/complete', [OrderController::class, 'complete'])
            ->middleware('permission:access-call-center|manage-call-center|create-orders|manage-orders');
        Route::apiResource('orders', OrderController::class)
            ->except(['destroy'])
            ->middleware('permission:view-orders|create-orders|manage-orders');
        Route::post('orders/{order}/items', [OrderController::class, 'addItem'])
            ->middleware('permission:create-orders|manage-orders');
        Route::delete('orders/{order}/items/{orderItem}', [OrderController::class, 'removeItem'])
            ->middleware('permission:create-orders|manage-orders');
        Route::post('orders/{order}/items/{orderItem}/prepared', [OrderController::class, 'markItemPrepared'])
            ->middleware('permission:manage-orders');
        Route::post('orders/{order}/assembled', [OrderController::class, 'markAssembled'])
            ->middleware('permission:manage-orders');
        Route::post('orders/{order}/expedite', [OrderController::class, 'expedite'])
            ->middleware('permission:access-call-center|manage-call-center|manage-orders');
        Route::post('orders/{order}/customer-experience', [OrderController::class, 'storeCustomerExperience'])
            ->middleware('permission:access-call-center|manage-call-center|manage-orders');
        Route::post('orders/{order}/delivered', [OrderController::class, 'markDelivered'])
            ->middleware('permission:manage-orders');
        Route::post('orders/offline-deliveries/sync', [OrderController::class, 'syncOfflineDeliveries'])
            ->middleware('permission:manage-orders');
        Route::post('orders/{order}/confirm', [OrderController::class, 'confirm'])
            ->middleware('permission:create-orders|manage-orders');
        Route::post('orders/{order}/serve', [OrderController::class, 'serve'])
            ->middleware('permission:manage-orders');
        Route::get('orders/{order}/journal-entry', [OrderController::class, 'journalEntry'])
            ->middleware('permission:manage-accounting|post-journal');
        Route::get('orders/{order}/print-sections', [OrderController::class, 'printSections']);
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])
            ->middleware('permission:manage-orders');

        Route::get('production-tickets', [ProductionTicketController::class, 'index']);
        Route::post('production-tickets/{ticket}/start', [ProductionTicketController::class, 'startPreparing']);
        Route::post('production-tickets/{ticket}/ready', [ProductionTicketController::class, 'markReady']);
        Route::post('production-tickets/{ticket}/served', [ProductionTicketController::class, 'markServed']);

        Route::post('orders/{order}/invoice', [InvoiceController::class, 'createFromOrder'])
            ->middleware('permission:access-call-center|manage-call-center|close-invoices|manage-invoices');
        Route::post('orders/{order}/close', [InvoiceController::class, 'createFromOrder'])
            ->middleware('permission:access-call-center|manage-call-center|close-invoices|manage-invoices');
        Route::get('invoices', [InvoiceController::class, 'index'])
            ->middleware('permission:manage-invoices');
        Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'addPayment'])
            ->middleware('permission:access-call-center|manage-call-center|add-payments|manage-payments|manage-invoices');
        Route::get('invoices/{invoice}/journal-entry', [InvoiceController::class, 'journalEntry'])
            ->middleware('permission:manage-accounting|post-journal');

        // أ¢â€‌â‚¬أ¢â€‌â‚¬ Settlement & Payment Routing أ¢â€‌â‚¬أ¢â€‌â‚¬
        Route::post('orders/{order}/settle', [\App\Http\Controllers\Api\SettleController::class, 'settle'])
            ->middleware('permission:add-payments|manage-payments|manage-invoices');
        Route::get('orders/{order}/settlement', [\App\Http\Controllers\Api\SettleController::class, 'show'])
            ->middleware('permission:add-payments|manage-payments|manage-invoices');
        Route::apiResource('payment-methods', \App\Http\Controllers\Api\PaymentMethodController::class)
            ->middleware('permission:manage-payments|manage-invoices');

    }); // أ¢â€ ع¯ ط¸â€ ط¸â€،ط·آ§ط¸ظ¹ط·آ© ط¸â€¦ط·آ³ط·آ§ط·آ±ط·آ§ط·ع¾ POS ط·آ§ط¸â€‍ط¸â€¦ط·آ­ط¸â€¦ط¸ظ¹ط·آ© ط·آ¨ط·آ´ط·آ¨ط¸ئ’ط·آ© ط·آ§ط¸â€‍ط¸ظ¾ط·آ±ط·آ¹

    // أ¢â€‌â‚¬أ¢â€‌â‚¬ Call Center أ¢â€‌â‚¬أ¢â€‌â‚¬
    Route::prefix('call-center')->middleware('permission:access-call-center|manage-call-center')->group(function () {
        Route::put('orders/{order}/complete', [OrderController::class, 'complete']);
        Route::post('orders/{order}/settle', [\App\Http\Controllers\Api\SettleController::class, 'settle']);
        Route::get('orders/{order}/settlement', [\App\Http\Controllers\Api\SettleController::class, 'show']);
        Route::get('payment-methods', [\App\Http\Controllers\Api\PaymentMethodController::class, 'index']);
        Route::get('customers/search', [CallCenterController::class, 'searchCustomers']);
        Route::post('customers', [CallCenterController::class, 'storeCustomer']);
        Route::get('customers/analytics', [CallCenterController::class, 'analytics']);
        Route::get('customers/top', [CallCenterController::class, 'topCustomers']);
        Route::get('customers/{customer}/profile', [CallCenterController::class, 'customerProfile']);
        Route::get('customers/{customer}/full-profile', [CallCenterController::class, 'customerFullProfile']);
        Route::get('customers/{customer}/orders', [CallCenterController::class, 'customerOrders']);
        Route::get('customers/{customer}/favorites', [CallCenterController::class, 'customerFavorites']);
        Route::post('customers/quick-create', [CallCenterController::class, 'quickCreateCustomer']);
        Route::get('customers/{customer}/occasions', [CallCenterController::class, 'customerOccasions']);
        Route::post('customers/{customer}/occasions', [CallCenterController::class, 'storeOccasion']);
        Route::get('customers/{customer}/notes', [CallCenterController::class, 'customerNotes']);
        Route::post('customers/{customer}/notes', [CallCenterController::class, 'storeNote']);
        Route::get('customers/{customer}/important-notes', [CallCenterController::class, 'customerImportantNotes']);
        Route::post('customers/{customer}/addresses', [CallCenterController::class, 'storeAddress']);
        Route::patch('customer-addresses/{address}', [CallCenterController::class, 'updateAddress']);
        Route::post('customer-addresses/{address}/use', [CallCenterController::class, 'markAddressUsed']);
        Route::patch('customer-occasions/{occasion}', [CallCenterController::class, 'updateOccasion']);
        Route::delete('customer-occasions/{occasion}', [CallCenterController::class, 'deleteOccasion']);
        Route::get('occasions', [CallCenterController::class, 'occasionsByRange']);
        Route::get('customers/{customer}/addresses', [CallCenterController::class, 'customerAddresses']);
        Route::get('customers/{customer}/complaints', [CallCenterController::class, 'customerComplaints']);
        Route::get('customers/{customer}/alerts', [CallCenterController::class, 'customerAlerts']);
        Route::get('orders/{order}', [CallCenterController::class, 'orderDetails']);
        Route::get('complaints', [CallCenterController::class, 'complaintsIndex']);
        Route::post('complaints', [CallCenterController::class, 'storeComplaint']);
        Route::get('complaints/{complaint}', [CallCenterController::class, 'showComplaint']);
        Route::patch('complaints/{complaint}', [CallCenterController::class, 'updateComplaint']);
        Route::post('complaints/{complaint}/followups', [CallCenterController::class, 'addFollowup']);
        Route::get('complaints/{complaint}/timeline', [CallCenterController::class, 'complaintTimeline']);
    });

    // أ¢â€‌â‚¬أ¢â€‌â‚¬ ط·آ¥ط·آ¯ط·آ§ط·آ±ط·آ© ط·آ§ط¸â€‍ط¸â€¦ط·آ³ط·ع¾ط·آ®ط·آ¯ط¸â€¦ط¸ظ¹ط¸â€  أ¢â€‌â‚¬أ¢â€‌â‚¬
    Route::get('users', [UserController::class, 'index'])->middleware('permission:manage-users');
    Route::post('users', [UserController::class, 'store'])->middleware('permission:manage-users');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('permission:manage-users');
    Route::get('roles', [UserController::class, 'roles'])->middleware('permission:manage-users');
    Route::put('users/{user}/role', [UserController::class, 'updateRole'])->middleware('permission:manage-users');
    Route::post('users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->middleware('permission:manage-users');

    // أ¢â€‌â‚¬أ¢â€‌â‚¬ ط·آ¥ط·آ¯ط·آ§ط·آ±ط·آ© ط·آ§ط¸â€‍ط·آ£ط·آ¯ط¸ث†ط·آ§ط·آ± ط¸ث†ط·آ§ط¸â€‍ط·آµط¸â€‍ط·آ§ط·آ­ط¸ظ¹ط·آ§ط·ع¾ أ¢â€‌â‚¬أ¢â€‌â‚¬
    Route::get('roles-list', [RoleController::class, 'index'])->middleware('permission:manage-users');
    Route::get('permissions-list', [RoleController::class, 'getAllPermissions'])->middleware('permission:manage-users');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:manage-users');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:manage-users');
    Route::put('roles/{role}/permissions', [RoleController::class, 'updatePermissions'])->middleware('permission:manage-users');

    // أ¢â€‌â‚¬أ¢â€‌â‚¬ Branches أ¢â€‌â‚¬أ¢â€‌â‚¬
    Route::get('branches/{branch}', [BranchController::class, 'show'])->middleware('permission:manage-branches');
    Route::post('branches', [BranchController::class, 'store'])->middleware('permission:manage-branches');
    Route::put('branches/{branch}', [BranchController::class, 'update'])->middleware('permission:manage-branches');
    Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('permission:manage-branches');
    Route::get('branches/{branch}/menu', [BranchController::class, 'menu'])->middleware('permission:manage-branches');

    // أ¢â€‌â‚¬أ¢â€‌â‚¬ Items أ¢â€‌â‚¬أ¢â€‌â‚¬
    Route::post('items/upload-image', [ItemController::class, 'uploadImage']);
    Route::apiResource('items', ItemController::class);
    Route::apiResource('job-titles', JobTitleController::class);

    // أ¢â€‌â‚¬أ¢â€‌â‚¬ Employees أ¢â€‌â‚¬أ¢â€‌â‚¬
    Route::prefix("employees")->group(function () {
        Route::get("/financial-batch", [\App\Http\Controllers\Api\EmployeeFinancialController::class, "financialBatch"]);
        Route::get("/dashboard", [\App\Http\Controllers\Api\EmployeeFinancialController::class, "dashboard"]);
        Route::get("/analytics", [\App\Http\Controllers\Api\EmployeeFinancialController::class, "analytics"]);
        Route::post("/{employee}/advance", [\App\Http\Controllers\Api\EmployeeFinancialController::class, "recordAdvance"])
            ->middleware('permission:manage-accounting');
        Route::post("/{employee}/advance-repayment", [\App\Http\Controllers\Api\EmployeeFinancialController::class, "recordAdvanceRepayment"])
            ->middleware('permission:manage-accounting');
        Route::post("/{employee}/salary-accrual", [\App\Http\Controllers\Api\EmployeeFinancialController::class, "accrualSalary"])
            ->middleware('permission:manage-accounting');
        Route::post("/{employee}/salary-payment", [\App\Http\Controllers\Api\EmployeeFinancialController::class, "paySalary"])
            ->middleware('permission:manage-accounting');
        Route::get("/{employee}/financial-summary", [\App\Http\Controllers\Api\EmployeeFinancialController::class, "financialSummary"]);
        Route::get("/{employee}/account-statement", [\App\Http\Controllers\Api\EmployeeFinancialController::class, "accountStatement"]);
        Route::get("/{employee}/account-statement/export", [\App\Http\Controllers\Api\EmployeeFinancialController::class, "accountStatementExport"]);
        Route::get("/{employee}/account-statement/pdf", [\App\Http\Controllers\Api\EmployeeFinancialController::class, "accountStatementPdf"]);
        Route::post("/{employee}/loan", [\App\Http\Controllers\Api\EmployeeFinancialController::class, "recordLoan"])
            ->middleware('permission:manage-accounting');
        Route::post("/{employee}/loan-repayment", [\App\Http\Controllers\Api\EmployeeFinancialController::class, "recordLoanRepayment"])
            ->middleware('permission:manage-accounting');
        Route::get("/{employee}/loans", [\App\Http\Controllers\Api\EmployeeFinancialController::class, "getLoans"]);
        Route::post("/{employee}/settlement", [\App\Http\Controllers\Api\EmployeeFinancialController::class, "recordSettlement"])
            ->middleware('permission:manage-accounting');
    });
    Route::apiResource('employees', EmployeeController::class);

    // أ¢â€‌â‚¬أ¢â€‌â‚¬ Orders أ¢â€‌â‚¬أ¢â€‌â‚¬
    Route::post('orders/batch-invoice-ids', [InvoiceDetailsController::class, 'batchInvoiceIds']); // MUST be before apiResource
    Route::post('orders/{order}/void', [OrderController::class, 'void'])
        ->middleware('permission:manage-orders');
    Route::apiResource('orders', OrderController::class)
        ->except(['destroy'])
        ->middleware('permission:view-orders|create-orders|manage-orders');
    Route::post('orders/{order}/items', [OrderController::class, 'addItem'])
        ->middleware('permission:create-orders|manage-orders');
    Route::delete('orders/{order}/items/{orderItem}', [OrderController::class, 'removeItem'])
        ->middleware('permission:create-orders|manage-orders');
    Route::post('orders/offline-deliveries/sync', [OrderController::class, 'syncOfflineDeliveries'])
        ->middleware('permission:manage-orders');
    Route::post('orders/{order}/items/{orderItem}/prepared', [OrderController::class, 'markItemPrepared'])
        ->middleware('permission:manage-orders');
    Route::post('orders/{order}/assembled', [OrderController::class, 'markAssembled'])
        ->middleware('permission:manage-orders');
    Route::post('orders/{order}/expedite', [OrderController::class, 'expedite'])
        ->middleware('permission:access-call-center|manage-call-center|manage-orders');
    Route::post('orders/{order}/customer-experience', [OrderController::class, 'storeCustomerExperience'])
        ->middleware('permission:access-call-center|manage-call-center|manage-orders');
    Route::get('operations/delivery/available', [\App\Http\Controllers\Api\DeliveryOperationsController::class, 'available'])->middleware('permission:manage-orders');
    Route::get('operations/dashboard', [\App\Http\Controllers\Api\DeliveryOperationsController::class, 'dashboard'])->middleware('permission:view-orders|manage-orders');
    Route::get('operations/assemblers', [\App\Http\Controllers\Api\DeliveryOperationsController::class, 'assemblers'])->middleware('permission:manage-orders');
    Route::patch('operations/orders/{order}/assembly/start', [\App\Http\Controllers\Api\DeliveryOperationsController::class, 'startAssembly'])->middleware('permission:manage-orders');
    Route::patch('operations/orders/{order}/assembly/complete', [\App\Http\Controllers\Api\DeliveryOperationsController::class, 'completeAssembly'])->middleware('permission:manage-orders');
    Route::get('operations/orders/{order}/events', [\App\Http\Controllers\Api\DeliveryOperationsController::class, 'events'])->middleware('permission:view-orders|manage-orders');
    Route::patch('operations/orders/{order}/assign-delivery', [\App\Http\Controllers\Api\DeliveryOperationsController::class, 'assign'])->middleware('permission:manage-orders');
    Route::post('orders/{order}/delivered', [OrderController::class, 'markDelivered'])
        ->middleware('permission:manage-orders');
    Route::post('orders/{order}/confirm', [OrderController::class, 'confirm'])
        ->middleware('permission:create-orders|manage-orders');
    Route::post('orders/{order}/serve', [OrderController::class, 'serve'])
        ->middleware('permission:manage-orders');
    Route::get('orders/{order}/journal-entry', [OrderController::class, 'journalEntry'])
        ->middleware('permission:manage-accounting|post-journal');
    Route::get('orders/{order}/print-sections', [OrderController::class, 'printSections']);
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])
        ->middleware('permission:manage-orders');
    Route::post('orders/{order}/sync-pricing', [OrderController::class, 'syncPricing']);
    Route::get('orders/{order}/invoice-id', [InvoiceDetailsController::class, 'getInvoiceIdFromOrder']);

    Route::get('production-tickets', [ProductionTicketController::class, 'index']);
    Route::post('production-tickets/{ticket}/start', [ProductionTicketController::class, 'startPreparing']);
    Route::post('production-tickets/{ticket}/ready', [ProductionTicketController::class, 'markReady']);
    Route::post('production-tickets/{ticket}/served', [ProductionTicketController::class, 'markServed']);

    Route::post('orders/{order}/invoice', [InvoiceController::class, 'createFromOrder'])
        ->middleware('permission:access-call-center|manage-call-center|close-invoices|manage-invoices');
    Route::post('orders/{order}/close', [InvoiceController::class, 'createFromOrder'])
        ->middleware('permission:access-call-center|manage-call-center|close-invoices|manage-invoices'); // alias for cashier close action
    Route::get('invoices', [InvoiceController::class, 'index'])
        ->middleware('permission:manage-invoices');
    Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'addPayment'])
        ->middleware('permission:access-call-center|manage-call-center|add-payments|manage-payments|manage-invoices');
    Route::get('invoices/{invoice}/journal-entry', [InvoiceController::class, 'journalEntry'])
        ->middleware('permission:manage-accounting|post-journal');

    // أ¢â€‌â‚¬أ¢â€‌â‚¬ Invoice Details Drawer (lazy-load endpoints) أ¢â€‌â‚¬أ¢â€‌â‚¬
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

    // â”€â”€ Financial Invoices â”€â”€
    Route::prefix('financial/invoices')->middleware('permission:manage-invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'financialIndex']);
        Route::get('/stats', [InvoiceController::class, 'financialStats']);
        Route::get('/{invoice}', [InvoiceController::class, 'financialShow']);
        Route::post('/', [InvoiceController::class, 'financialStore']);
        Route::put('/{invoice}', [InvoiceController::class, 'financialUpdate']);
        Route::delete('/{invoice}', [InvoiceController::class, 'financialDestroy']);
        Route::post('/{invoice}/approve', [InvoiceController::class, 'approve']);
        Route::post('/{invoice}/void', [InvoiceController::class, 'voidFinancial']);
    });

    // أ¢â€‌â‚¬أ¢â€‌â‚¬ Sales Invoices (Full Module) أ¢â€‌â‚¬أ¢â€‌â‚¬
    Route::prefix('sales-invoices')->middleware('permission:manage-invoices')->group(function () {
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

    Route::prefix('accounting')->middleware('permission:view-accounting|manage-accounting')->group(function () {
        Route::apiResource('accounts', AccountController::class);
        Route::get('accounts/{account}/ledger', [AccountController::class, 'ledger']);
        Route::get('transactions/by-source', [TransactionController::class, 'bySource']);
        Route::apiResource('transactions', TransactionController::class);
        Route::post('transactions/{transaction}/post', [TransactionController::class, 'post']);
        Route::post('transactions/{transaction}/cancel', [TransactionController::class, 'cancel']);
        Route::apiResource('cost-centers', CostCenterController::class);
    });
});

// أ¢â€‌â‚¬أ¢â€‌â‚¬ POS Registers (Admin) أ¢â€‌â‚¬أ¢â€‌â‚¬
Route::middleware('auth:sanctum')->prefix('admin/pos-registers')->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\PosRegisterController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Admin\PosRegisterController::class, 'store']);
    Route::post('{id}/generate-token', [\App\Http\Controllers\Admin\PosRegisterController::class, 'generateActivationToken']);
    Route::post('{id}/revoke', [\App\Http\Controllers\Admin\PosRegisterController::class, 'revokeDevice']);
    // أ¢â€‌â‚¬أ¢â€‌â‚¬ Discount Management أ¢â€‌â‚¬أ¢â€‌â‚¬
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

Route::middleware('auth:sanctum')->apiResource('job-titles', \App\Http\Controllers\Api\JobTitleController::class);

// أ¢â€‌â‚¬أ¢â€‌â‚¬ Departments أ¢â€‌â‚¬أ¢â€‌â‚¬
Route::middleware('auth:sanctum')->prefix('departments')->group(function () {
    Route::get('/', [DepartmentController::class, 'index']);
    Route::get('/tree', [DepartmentController::class, 'tree']);
    Route::post('/', [DepartmentController::class, 'store']);
    Route::get('/{department}', [DepartmentController::class, 'show']);
    Route::put('/{department}', [DepartmentController::class, 'update']);
    Route::delete('/{department}', [DepartmentController::class, 'destroy']);
});

// أ¢â€‌â‚¬أ¢â€‌â‚¬ Suppliers أ¢â€‌â‚¬أ¢â€‌â‚¬
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

// أ¢â€‌â‚¬أ¢â€‌â‚¬ Customers أ¢â€‌â‚¬أ¢â€‌â‚¬
Route::middleware('auth:sanctum')->prefix("customers")->group(function () {
    Route::get("/", [\App\Http\Controllers\Api\CustomerFinancialController::class, "index"])
        ->middleware('permission:view-customers|create-customers|manage-customers');
    Route::post("/", [\App\Http\Controllers\Api\CustomerFinancialController::class, "store"])
        ->middleware('permission:create-customers|manage-customers');
    Route::get("/{customer}", [\App\Http\Controllers\Api\CustomerFinancialController::class, "show"])
        ->middleware('permission:view-customers|create-customers|manage-customers');
    Route::put("/{customer}", [\App\Http\Controllers\Api\CustomerFinancialController::class, "update"])
        ->middleware('permission:manage-customers');
    Route::delete("/{customer}", [\App\Http\Controllers\Api\CustomerFinancialController::class, "destroy"])
        ->middleware('permission:manage-customers');
    Route::post("/{customer}/invoice", [\App\Http\Controllers\Api\CustomerFinancialController::class, "recordInvoice"])
        ->middleware('permission:close-invoices|manage-invoices');
    Route::post("/{customer}/receipt", [\App\Http\Controllers\Api\CustomerFinancialController::class, "recordReceipt"])
        ->middleware('permission:add-payments|manage-payments|manage-invoices');
    Route::post("/{customer}/payment", [\App\Http\Controllers\Api\CustomerFinancialController::class, "recordReceipt"])
        ->middleware('permission:add-payments|manage-payments|manage-invoices');
    Route::post("/{customer}/credit-note", [\App\Http\Controllers\Api\CustomerFinancialController::class, "recordCreditNote"])
        ->middleware('permission:manage-accounting|manage-invoices');
    Route::post("/{customer}/debit-note", [\App\Http\Controllers\Api\CustomerFinancialController::class, "recordDebitNote"])
        ->middleware('permission:manage-accounting|manage-invoices');
    Route::get("/{customer}/statement", [\App\Http\Controllers\Api\CustomerFinancialController::class, "statement"])
        ->middleware('permission:manage-customers|view-accounting|manage-accounting');
    Route::get("/{customer}/statement/export", [\App\Http\Controllers\Api\CustomerFinancialController::class, "statementExport"])
        ->middleware('permission:manage-customers|view-accounting|manage-accounting');
    Route::get("/{customer}/statement/pdf", [\App\Http\Controllers\Api\CustomerFinancialController::class, "statementPdf"])
        ->middleware('permission:manage-customers|view-accounting|manage-accounting');
    Route::get("/{customer}/aging", [\App\Http\Controllers\Api\CustomerFinancialController::class, "aging"])
        ->middleware('permission:manage-customers|view-accounting|manage-accounting');
    Route::get("/{customer}/analytics", [\App\Http\Controllers\Api\CustomerFinancialController::class, "analytics"])
        ->middleware('permission:manage-customers|view-accounting|manage-accounting');
    Route::get("/aging-report", [\App\Http\Controllers\Api\CustomerFinancialController::class, "agingReport"])
        ->middleware('permission:manage-customers|view-accounting|manage-accounting');
    Route::get("/collection-report", [\App\Http\Controllers\Api\CustomerFinancialController::class, "collectionReport"])
        ->middleware('permission:manage-customers|view-accounting|manage-accounting');
});

Route::post('pos/activate', [\App\Http\Controllers\Admin\PosRegisterController::class, 'activate']);

Route::middleware('auth:sanctum')->post('pos/check-status', [\App\Http\Controllers\Admin\PosRegisterController::class, 'checkStatus']);

// أ¢â€‌â‚¬أ¢â€‌â‚¬ Hospitality Devices (Admin) أ¢â€‌â‚¬أ¢â€‌â‚¬
Route::middleware('auth:sanctum')->prefix('admin/hospitality-devices')->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\HospitalityDeviceController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Admin\HospitalityDeviceController::class, 'store']);
    Route::post('{id}/generate-token', [\App\Http\Controllers\Admin\HospitalityDeviceController::class, 'generateActivationToken']);
    Route::post('{id}/revoke', [\App\Http\Controllers\Admin\HospitalityDeviceController::class, 'revokeDevice']);
});

// أ¢â€‌â‚¬أ¢â€‌â‚¬ Hospitality Activation (Public) أ¢â€‌â‚¬أ¢â€‌â‚¬
Route::post('hospitality/activate', [\App\Http\Controllers\Admin\HospitalityDeviceController::class, 'activate']);
Route::middleware('auth:sanctum')->post('hospitality/check-status', [\App\Http\Controllers\Admin\HospitalityDeviceController::class, 'checkStatus']);

// أ¢â€‌â‚¬أ¢â€‌â‚¬ Dining Zones (Admin) أ¢â€‌â‚¬أ¢â€‌â‚¬
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

// أ¢â€‌â‚¬أ¢â€‌â‚¬ Dining Zones (POS / Hospitality) أ¢â€‌â‚¬أ¢â€‌â‚¬
Route::middleware('auth:sanctum')->prefix('dining-zones')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\DiningZoneController::class, 'index']);
    Route::get('/{id}', [\App\Http\Controllers\Api\DiningZoneController::class, 'show']);
});

Route::middleware('auth:sanctum')->post('pos/check-status', [\App\Http\Controllers\Admin\PosRegisterController::class, 'checkStatus']);
