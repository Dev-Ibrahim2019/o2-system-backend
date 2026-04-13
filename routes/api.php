<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Administration\DepartmentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

// تسجيل الدخول
Route::post('/login', [AuthController::class, 'login']);


/*
|--------------------------------------------------------------------------
| Protected Routes (Sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // تسجيل الخروج
    Route::post('/logout', [AuthController::class, 'logout']);

    // المستخدم الحالي
    Route::get('/auth/me', function (Request $request) {
        return response()->json([
            'user' => $request->user()
        ]);
    });

    // Departments CRUD

});
Route::apiResource('departments', DepartmentController::class);
