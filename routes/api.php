<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BranchController;
use App\Models\User;
// use App\Http\Controllers\Auth\LoginController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return User::whereId(2)->get(['name', 'email']);
});

Route::post('/login', [AuthController::class, 'login']);
// Route::post('/logout', [LoginController::class, 'logout']);

Route::post('logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth:sanctum')->get("/auth/me", function (Request $request) {
    return response()->json(['user' => $request->user()]);
});

Route::post('/branches', [BranchController::class, 'store']);
