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
    Route::apiResource('employees', EmployeeController::class);
    Route::apiResource('job-titles', JobTitleController::class);

    Route::post('orders/{order}/void', [OrderController::class, 'void']);
    Route::apiResource('orders', OrderController::class)->except(['destroy']);
    Route::post('orders/{order}/confirm', [OrderController::class, 'confirm']);
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);

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
// Route::apiResource('departments', DepartmentController::class);
// Route::post('departments/{department}/branches/{branch}', [DepartmentController::class, 'attachBranch']);
// Route::delete('departments/{department}/branches/{branch}', [DepartmentController::class, 'detachBranch']);

// Route::get('items/{item}/usages', [ItemController::class, 'usages']);
// Route::apiResource('items', ItemController::class);

Route::apiResource('employees', EmployeeController::class);  // ← أضف
Route::apiResource('job-titles', \App\Http\Controllers\Api\JobTitleController::class);

Route::prefix('departments')->group(function () {
    Route::get('/',        [DepartmentController::class, 'index']);
    Route::get('/tree',    [DepartmentController::class, 'tree']);   // ← nested tree
    Route::post('/',       [DepartmentController::class, 'store']);
    Route::put('/{department}',    [DepartmentController::class, 'update']);
    Route::delete('/{department}', [DepartmentController::class, 'destroy']);
});
