<?php

// routes/api.php
//
// ✅ الإصلاحات المطبّقة في قسم accounting routes:
//
// ❌ حُذف:
//   Route::get('transactions/by-source', 'bySource')
//   → الدالة bySource() غير موجودة في TransactionController → كانت تُعطي 500 Error
//
// ✅ أُضيفت:
//   GET  accounting/accounts/suggest-code          → AccountController::suggestCode()
//   GET  accounting/transactions/stats/daily       → TransactionController::dailyStats()
//   GET  accounting/transactions/stats/comprehensive → TransactionController::comprehensiveStats()
//   GET  accounting/transactions/{id}/entries      → TransactionController::getEntries()
//
// ⚠️  ترتيب مهم: routes الثابتة (stats/daily, stats/comprehensive, suggest-code)
//    يجب أن تأتي قبل routes الـ parameters ({transaction}, {account})
//    لأن Laravel يُطابق أول route يجد تطابقاً، فلو جاء apiResource أولاً
//    سيُفسَّر "stats" كـ {transaction} id وينتهي بـ 404

use App\Http\Controllers\Api\Accounting\AccountController;
use App\Http\Controllers\Api\Accounting\CostCenterController;
use App\Http\Controllers\Api\Accounting\TransactionController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CustomerFinancialController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmployeeFinancialController;
use App\Http\Controllers\Api\SupplierFinancialController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\JobTitleController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Auth\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ── Public routes ────────────────────────────────────────────────────
Route::get('menu', [MenuController::class, 'index']);
Route::get('branches', [BranchController::class, 'index']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', fn(Request $r) => response()->json(['user' => $r->user()]));

    // ── Branches ─────────────────────────────────────────────────────
    Route::apiResource('branches', BranchController::class);
    Route::get('branches/{branch}/menu', [BranchController::class, 'menu']);

    // ── Items ────────────────────────────────────────────────────────
    Route::post('items/upload-image', [ItemController::class, 'uploadImage']);
    Route::apiResource('items', ItemController::class);

    // ── Employees & Finance ───────────────────────────────────────────
    Route::apiResource('employees', EmployeeController::class);
    Route::prefix('employees/{employee}')->group(function () {
        Route::post('advance',           [EmployeeFinancialController::class, 'recordAdvance']);
        Route::post('advance-repayment', [EmployeeFinancialController::class, 'recordAdvanceRepayment']);
        Route::post('salary-accrual',    [EmployeeFinancialController::class, 'accrualSalary']);
        Route::post('salary-payment',    [EmployeeFinancialController::class, 'paySalary']);
        Route::get('account-statement',  [EmployeeFinancialController::class, 'accountStatement']);
    });

    // ── Customers & Finance ───────────────────────────────────────────
    Route::apiResource('customers', CustomerFinancialController::class);
    Route::prefix('customers/{customer}')->group(function () {
        Route::post('invoice',  [CustomerFinancialController::class, 'recordInvoice']);
        Route::post('payment',  [CustomerFinancialController::class, 'recordPayment']);
        Route::get('statement', [CustomerFinancialController::class, 'accountStatement']);
    });

    // ── Suppliers & Finance ───────────────────────────────────────────
    Route::prefix('suppliers/{supplier}')->group(function () {
        Route::post('bill',     [SupplierFinancialController::class, 'recordBill']);
        Route::post('payment',  [SupplierFinancialController::class, 'recordPayment']);
        Route::get('statement', [SupplierFinancialController::class, 'accountStatement']);
    });

    // ── Job Titles ────────────────────────────────────────────────────
    Route::apiResource('job-titles', JobTitleController::class);

    // ── Orders ────────────────────────────────────────────────────────
    Route::post('orders/{order}/void',    [OrderController::class, 'void']);
    Route::post('orders/{order}/confirm', [OrderController::class, 'confirm']);
    Route::post('orders/{order}/cancel',  [OrderController::class, 'cancel']);
    Route::apiResource('orders', OrderController::class)->except(['destroy']);

    // ── Accounting ────────────────────────────────────────────────────
    Route::prefix('accounting')->group(function () {

        // ✅ Accounts
        // suggest-code يجب أن يأتي قبل apiResource لتجنب تفسير "suggest-code" كـ {account}
        Route::get('accounts/suggest-code', [AccountController::class, 'suggestCode']);
        Route::get('accounts/{account}/ledger', [AccountController::class, 'ledger']);
        Route::apiResource('accounts', AccountController::class);

        // ✅ Transactions
        // routes الثابتة أولاً، ثم routes الـ parameters، ثم apiResource
        Route::get('transactions/stats/daily',          [TransactionController::class, 'dailyStats']);
        Route::get('transactions/stats/comprehensive',  [TransactionController::class, 'comprehensiveStats']);
        Route::get('transactions/{transaction}/entries', [TransactionController::class, 'getEntries']);
        Route::post('transactions/{transaction}/post',  [TransactionController::class, 'post']);
        Route::post('transactions/{transaction}/cancel', [TransactionController::class, 'cancel']);
        Route::post('transactions/{transaction}/reverse', [TransactionController::class, 'reverse']);
        Route::apiResource('transactions', TransactionController::class);

        // ✅ Cost Centers
        Route::apiResource('cost-centers', CostCenterController::class);
    });

    // ── Departments ───────────────────────────────────────────────────
    Route::prefix('departments')->group(function () {
        Route::get('/',                [DepartmentController::class, 'index']);
        Route::get('/tree',            [DepartmentController::class, 'tree']);
        Route::post('/',               [DepartmentController::class, 'store']);
        Route::put('/{department}',    [DepartmentController::class, 'update']);
        Route::delete('/{department}', [DepartmentController::class, 'destroy']);
    });
});
