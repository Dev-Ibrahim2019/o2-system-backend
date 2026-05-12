<?php

use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Auth\AuthController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return User::whereId(2)->get(['name', 'email']);
});

Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->post('logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth:sanctum')->get("/auth/me", function (Request $request) {
    return response()->json(['user' => $request->user()]);
});


Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('branches', BranchController::class);
    Route::get('branches/{branch}/menu', [BranchController::class, 'menu']);

    Route::post('items/upload-image', [ItemController::class, 'uploadImage']);
    Route::apiResource('items', ItemController::class);

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

});
