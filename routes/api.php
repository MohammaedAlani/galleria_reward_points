<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('logout', [AuthController::class, 'logout']);
    });

    Route::group(['middleware' => ['auth:sanctum']], function () {
        Route::apiResource('customers', CustomerController::class);
    });

//    Route::apiResource('transactions', 'TransactionController');
//    Route::apiResource('users', 'UserController');
});
