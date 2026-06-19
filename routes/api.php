<?php
// routes/api.php — النسخة الكاملة

use App\Http\Controllers\Api\Accounting\AccountController;
use App\Http\Controllers\Api\Accounting\CostCenterController;
use App\Http\Controllers\Api\Accounting\TransactionController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\FinancialTransactionController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\JobTitleController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductionTicketController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\TableController;
use App\Http\Controllers\Auth\AuthController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// routes/api.php

// ── Public routes (لا تحتاج auth) ──────────────────────
Route::get('menu', [MenuController::class, 'index']);
Route::get('branches', [BranchController::class, 'index']); // ✅ أضف هاد

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', fn(Request $r) => response()->json(['user' => $r->user()]));

    // branches CRUD (post/put/delete محمية، GET فوق public)
    Route::post('branches', [BranchController::class, 'store']);
    Route::put('branches/{branch}', [BranchController::class, 'update']);
    Route::delete('branches/{branch}', [BranchController::class, 'destroy']);
    Route::get('branches/{branch}', [BranchController::class, 'show']);
    Route::get('branches/{branch}/menu', [BranchController::class, 'menu']);

    Route::post('items/upload-image', [ItemController::class, 'uploadImage']);
    Route::apiResource('items', ItemController::class);
    Route::apiResource('job-titles', JobTitleController::class);

    // ── Employee Routes ──────────────────────────────────────
    // IMPORTANT: Static routes (financial-batch, dashboard, analytics) MUST come
    // BEFORE apiResource to prevent wildcard {employee} from catching them
    Route::prefix("employees")->group(function () {
        // Batch data & Dashboard & Analytics (static paths)
        Route::get("/financial-batch",       [\App\Http\Controllers\Api\EmployeeFinancialController::class, "financialBatch"]);
        Route::get("/dashboard",             [\App\Http\Controllers\Api\EmployeeFinancialController::class, "dashboard"]);
        Route::get("/analytics",             [\App\Http\Controllers\Api\EmployeeFinancialController::class, "analytics"]);

        // Employee-specific financial operations (with {employee} wildcard)
        Route::post("/{employee}/advance",           [\App\Http\Controllers\Api\EmployeeFinancialController::class, "recordAdvance"]);
        Route::post("/{employee}/advance-repayment", [\App\Http\Controllers\Api\EmployeeFinancialController::class, "recordAdvanceRepayment"]);
        Route::post("/{employee}/salary-accrual",    [\App\Http\Controllers\Api\EmployeeFinancialController::class, "accrualSalary"]);
        Route::post("/{employee}/salary-payment",    [\App\Http\Controllers\Api\EmployeeFinancialController::class, "paySalary"]);
        Route::get("/{employee}/account-statement",  [\App\Http\Controllers\Api\EmployeeFinancialController::class, "accountStatement"]);
        Route::post("/{employee}/loan",              [\App\Http\Controllers\Api\EmployeeFinancialController::class, "recordLoan"]);
        Route::post("/{employee}/loan-repayment",    [\App\Http\Controllers\Api\EmployeeFinancialController::class, "recordLoanRepayment"]);
        Route::get("/{employee}/loans",              [\App\Http\Controllers\Api\EmployeeFinancialController::class, "getLoans"]);
        Route::post("/{employee}/settlement",        [\App\Http\Controllers\Api\EmployeeFinancialController::class, "recordSettlement"]);
    });

    // Employee CRUD — MUST be after static financial routes to avoid {employee} conflicts
    Route::apiResource('employees', EmployeeController::class);

    Route::post('orders/{order}/void', [OrderController::class, 'void']);
    Route::apiResource('orders', OrderController::class)->except(['destroy']);
    Route::post('orders/{order}/items', [OrderController::class, 'addItem']);
    Route::delete('orders/{order}/items/{orderItem}', [OrderController::class, 'removeItem']);
    Route::post('orders/{order}/confirm', [OrderController::class, 'confirm']);
    Route::post('orders/{order}/serve', [OrderController::class, 'serve']);
    Route::get('orders/{order}/journal-entry', [OrderController::class, 'journalEntry']);
    Route::get('orders/{order}/print-sections', [OrderController::class, 'printSections']);
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);

    Route::get('production-tickets', [ProductionTicketController::class, 'index']);
    Route::post('production-tickets/{ticket}/start', [ProductionTicketController::class, 'startPreparing']);
    Route::post('production-tickets/{ticket}/ready', [ProductionTicketController::class, 'markReady']);
    Route::post('production-tickets/{ticket}/served', [ProductionTicketController::class, 'markServed']);

    Route::post('orders/{order}/invoice', [InvoiceController::class, 'createFromOrder']);
    Route::post('orders/{order}/close', [InvoiceController::class, 'createFromOrder']); // alias for cashier close action
    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'addPayment']);
    Route::get('invoices/{invoice}/journal-entry', [InvoiceController::class, 'journalEntry']);

    Route::prefix('accounting')->group(function () {

        // ── دليل الحسابات ─────────────────────────────────────────────────────────
        Route::apiResource('accounts', AccountController::class);
        Route::get('accounts/{account}/ledger', [AccountController::class, 'ledger']);
        // GET /accounting/accounts?tree=true         → شجرة الحسابات
        // GET /accounting/accounts?type=asset        → حسابات الأصول
        // GET /accounting/accounts?with_balance=true → مع الأرصدة
        // GET /accounting/accounts/{id}/ledger?from=2026-01-01&to=2026-12-31

        // ── القيود المحاسبية ──────────────────────────────────────────────────────
        Route::get('transactions/by-source', [TransactionController::class, 'bySource']);
        Route::apiResource('transactions', TransactionController::class);
        Route::post('transactions/{transaction}/post',   [TransactionController::class, 'post']);
        Route::post('transactions/{transaction}/cancel', [TransactionController::class, 'cancel']);
        // POST /accounting/transactions                → إنشاء قيد (draft)
        // POST /accounting/transactions/{id}/post     → ترحيل القيد
        // POST /accounting/transactions/{id}/cancel   → إلغاء القيد

        // ── مراكز التكلفة ─────────────────────────────────────────────────────────
        Route::apiResource('cost-centers', CostCenterController::class);
        // GET /accounting/cost-centers?tree=true → شجرة مراكز التكلفة
    });
});

// ── Public employee routes (للمستخدمين غير المسجلين) ──
// route::apiResource('employees', EmployeeController::class);  // ← أضف إذا أردت public access

// ── Job Titles (public) ─────────────────────────────────
Route::apiResource('job-titles', \App\Http\Controllers\Api\JobTitleController::class);

// ── Departments ─────────────────────────────────────────
Route::prefix('departments')->group(function () {
    Route::get('/',        [DepartmentController::class, 'index']);
    Route::get('/tree',    [DepartmentController::class, 'tree']);   // ← nested tree
    Route::post('/',       [DepartmentController::class, 'store']);
    Route::put('/{department}',    [DepartmentController::class, 'update']);
    Route::delete('/{department}', [DepartmentController::class, 'destroy']);
});

// ── SUPPLIER ROUTES ──────────────────────────────────────────
Route::prefix("suppliers")->group(function () {
    // CRUD
    Route::get("/",                    [\App\Http\Controllers\Api\SupplierFinancialController::class, "index"]);
    Route::post("/",                   [\App\Http\Controllers\Api\SupplierFinancialController::class, "store"]);
    Route::get("/{supplier}",          [\App\Http\Controllers\Api\SupplierFinancialController::class, "show"]);
    Route::put("/{supplier}",          [\App\Http\Controllers\Api\SupplierFinancialController::class, "update"]);
    Route::delete("/{supplier}",       [\App\Http\Controllers\Api\SupplierFinancialController::class, "destroy"]);

    // Accounting Operations
    Route::post("/{supplier}/bill",          [\App\Http\Controllers\Api\SupplierFinancialController::class, "recordBill"]);
    Route::post("/{supplier}/payment",       [\App\Http\Controllers\Api\SupplierFinancialController::class, "recordPayment"]);
    Route::post("/{supplier}/credit-note",   [\App\Http\Controllers\Api\SupplierFinancialController::class, "recordCreditNote"]);
    Route::post("/{supplier}/debit-note",    [\App\Http\Controllers\Api\SupplierFinancialController::class, "recordDebitNote"]);
    Route::get("/{supplier}/statement",      [\App\Http\Controllers\Api\SupplierFinancialController::class, "statement"]);
    Route::get("/{supplier}/aging",              [\App\Http\Controllers\Api\SupplierFinancialController::class, "aging"]);
    Route::get("/{supplier}/monthly-payments",   [\App\Http\Controllers\Api\SupplierFinancialController::class, "monthlyPayments"]);
    Route::get("/{supplier}/transactions",       [\App\Http\Controllers\Api\SupplierFinancialController::class, "transactions"]);

    // Reports
    Route::get("/aging-report",              [\App\Http\Controllers\Api\SupplierFinancialController::class, "agingReport"]);
});

// ── CUSTOMER ROUTES ─────────────────────────────────────────
Route::prefix("customers")->group(function () {
    // CRUD
    Route::get("/",                    [\App\Http\Controllers\Api\CustomerFinancialController::class, "index"]);
    Route::post("/",                   [\App\Http\Controllers\Api\CustomerFinancialController::class, "store"]);
    Route::get("/{customer}",          [\App\Http\Controllers\Api\CustomerFinancialController::class, "show"]);
    Route::put("/{customer}",          [\App\Http\Controllers\Api\CustomerFinancialController::class, "update"]);
    Route::delete("/{customer}",       [\App\Http\Controllers\Api\CustomerFinancialController::class, "destroy"]);

    // Accounting Operations
    Route::post("/{customer}/invoice",       [\App\Http\Controllers\Api\CustomerFinancialController::class, "recordInvoice"]);
    Route::post("/{customer}/receipt",       [\App\Http\Controllers\Api\CustomerFinancialController::class, "recordReceipt"]);
    Route::post("/{customer}/credit-note",   [\App\Http\Controllers\Api\CustomerFinancialController::class, "recordCreditNote"]);
    Route::post("/{customer}/debit-note",    [\App\Http\Controllers\Api\CustomerFinancialController::class, "recordDebitNote"]);
    Route::get("/{customer}/statement",      [\App\Http\Controllers\Api\CustomerFinancialController::class, "statement"]);
    Route::get("/{customer}/aging",          [\App\Http\Controllers\Api\CustomerFinancialController::class, "aging"]);
    Route::get("/{customer}/analytics",      [\App\Http\Controllers\Api\CustomerFinancialController::class, "analytics"]);

    // Reports
    Route::get("/aging-report",              [\App\Http\Controllers\Api\CustomerFinancialController::class, "agingReport"]);
    Route::get("/collection-report",         [\App\Http\Controllers\Api\CustomerFinancialController::class, "collectionReport"]);
});
