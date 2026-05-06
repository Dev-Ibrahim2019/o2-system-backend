<?php
// routes/api.php — النسخة المحدّثة

use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\JobTitleController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductionTicketController;
use App\Http\Controllers\Auth\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ── المنيو العام (لا يحتاج مصادقة للعرض) ─────────────────────────────────────
Route::get('menu', [MenuController::class, 'index']);

// ── المصادقة (بدون Sanctum) ──────────────────────────────────────────────────
Route::post('/login', [AuthController::class, 'login']);

// ── المسارات المحمية بـ Sanctum ──────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // الخروج وبيانات الجلسة
    Route::post('/logout',   [AuthController::class, 'logout'])->name('logout');
    Route::get('/auth/me',   [AuthController::class, 'me']);

    // مسار /user للتوافق مع الكود القديم
    Route::get('/user', fn(Request $r) => response()->json([
        'data' => [
            'id'   => $r->user()->id,
            'name' => $r->user()->employee?->name ?? $r->user()->name,
            'role' => $r->user()->employee?->role ?? 'EMPLOYEE',
        ],
    ]));

    // ── الفروع ────────────────────────────────────────────────────────────
    Route::apiResource('branches', BranchController::class);
    Route::get('branches/{branch}/menu', [BranchController::class, 'menu']);

    // ── الأقسام ───────────────────────────────────────────────────────────
    Route::apiResource('departments', DepartmentController::class);
    Route::post(
        'departments/{department}/branches/{branch}',
        [DepartmentController::class, 'attachBranch']
    );
    Route::delete(
        'departments/{department}/branches/{branch}',
        [DepartmentController::class, 'detachBranch']
    );

    // ── الأصناف ───────────────────────────────────────────────────────────
    Route::get('items/{item}/usages', [ItemController::class, 'usages']);
    Route::apiResource('items', ItemController::class);

    // ── الموظفون والمسميات ────────────────────────────────────────────────
    Route::apiResource('employees',  EmployeeController::class);
    Route::apiResource('job-titles', JobTitleController::class);

    // ── الطلبات ───────────────────────────────────────────────────────────
    Route::apiResource('orders', OrderController::class);
    Route::post('orders/{order}/confirm', [OrderController::class, 'confirm']);
    Route::post('orders/{order}/cancel',  [OrderController::class, 'cancel']);
    Route::post('orders/{order}/void',    [OrderController::class, 'void']);
    Route::post('orders/{order}/pay',     [OrderController::class, 'pay']);

    // التحقق من الرقم المرجعي
    Route::post('payments/verify-reference', [OrderController::class, 'verifyReference']);

    // ── تذاكر الأقسام الإنتاجية ──────────────────────────────────────────
    Route::get('production-tickets', [ProductionTicketController::class, 'index']);
    Route::patch(
        'production-tickets/{ticket}/status',
        [ProductionTicketController::class, 'updateStatus']
    );
    Route::patch(
        'production-tickets/{ticket}/items/{item}/status',
        [ProductionTicketController::class, 'updateItemStatus']
    );
});
