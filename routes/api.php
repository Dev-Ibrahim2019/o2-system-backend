<?php

<<<<<<< Updated upstream
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\DepartmentController;
=======
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\BranchController;
>>>>>>> Stashed changes
use App\Http\Controllers\Api\DepartmentItemController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\ItemGroupController;
use App\Http\Controllers\Api\RecipeController;
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

<<<<<<< Updated upstream
=======
// Route::middleware('auth:sanctum')->group(function () {
//     Route::apiResource('menu-item', MenuItemController::class);
// });
>>>>>>> Stashed changes
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('branches', BranchController::class);
    Route::get('branches/{branch}/menu', [BranchController::class, 'menu']);

    Route::apiResource('departments', DepartmentController::class);
    Route::post('departments/{department}/branches/{branch}', [DepartmentController::class, 'attachBranch']);
    Route::delete('departments/{department}/branches/{branch}', [DepartmentController::class, 'detachBranch']);

    Route::get('item-groups/tree', [ItemGroupController::class, 'tree']);
    Route::apiResource('item-groups', ItemGroupController::class);

    Route::get('items/{item}/usages', [ItemController::class, 'usages']);
    Route::apiResource('items', ItemController::class);

    Route::apiResource('department-items', DepartmentItemController::class);

    Route::get('recipes/{recipe}/cost', [RecipeController::class, 'cost']);
    Route::apiResource('recipes', RecipeController::class);
<<<<<<< Updated upstream
    Route::apiResource('employees',   EmployeeController::class);  // ← أضف
});
=======
});

Route::apiResource('job-titles', \App\Http\Controllers\Api\JobTitleController::class);

>>>>>>> Stashed changes
