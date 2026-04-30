<?php
// routes/api.php — النسخة الكاملة

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


// ── المنيو (بدل constants) ────────────────────────────────────────────────────
Route::get('menu', [MenuController::class, 'index']);

// ── الطلبات ──────────────────────────────────────────────────────────────────
Route::apiResource('orders', OrderController::class);
Route::post('orders/{order}/confirm', [OrderController::class, 'confirm']);
Route::post('orders/{order}/cancel',  [OrderController::class, 'cancel']);

// ── تذاكر الأقسام الإنتاجية (شاشة المطبخ/البار) ─────────────────────────────
Route::get('production-tickets', [ProductionTicketController::class, 'index']);
Route::patch(
    'production-tickets/{ticket}/status',
    [ProductionTicketController::class, 'updateStatus']
);
Route::patch(
    'production-tickets/{ticket}/items/{item}/status',
    [ProductionTicketController::class, 'updateItemStatus']
);

// ── المصادقة (بدون Sanctum) ─────────────────────────────────────────────────
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/auth/me', fn(Request $r) => response()->json(['user' => $r->user()]));

    // ── الفروع والأقسام والأصناف ──────────────────────────────────────────
    Route::apiResource('branches',    BranchController::class);
    Route::get('branches/{branch}/menu', [BranchController::class, 'menu']);

    Route::apiResource('departments', DepartmentController::class);
    Route::post('departments/{department}/branches/{branch}',   [DepartmentController::class, 'attachBranch']);
    Route::delete('departments/{department}/branches/{branch}', [DepartmentController::class, 'detachBranch']);

    Route::get('items/{item}/usages', [ItemController::class, 'usages']);
    Route::apiResource('items',       ItemController::class);

    // ── الموظفون والمسميات ────────────────────────────────────────────────
    Route::apiResource('employees',   EmployeeController::class);
    Route::apiResource('job-titles',  JobTitleController::class);




    Route::post('orders/{order}/void', [OrderController::class, 'void']);
    Route::apiResource('orders',       OrderController::class)->except(['destroy']);
});
