<?php

use App\Http\Controllers\Administration\DepartmentController;
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
    Route::apiResource('departments', DepartmentController::class);
});
