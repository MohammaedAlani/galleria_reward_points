<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('logout', [AuthController::class, 'logout']);
    });

    Route::group(['middleware' => ['auth:sanctum']], function () {
        Route::post('user/create', [AuthController::class, 'create']);
        Route::get('user', [AuthController::class, 'index']);

        Route::apiResource('customers', CustomerController::class);

        // Transaction routes
        Route::get('transactions', [TransactionController::class, 'index']);
        Route::get('transactions/pending', [TransactionController::class, 'pendingTransactions']);
        Route::post('transactions/approval/{transaction}/{status}', [TransactionController::class, 'approval']);
        Route::post('transactions/bulk-approve', [TransactionController::class, 'bulkApprove']);
        Route::post('transactions/bulk-reject', [TransactionController::class, 'bulkReject']);
        Route::post('transactions', [TransactionController::class, 'addTransaction']);
        Route::post('transactions/use', [TransactionController::class, 'useTransaction']);
        Route::post('transactions/return', [TransactionController::class, 'returnTransaction']);
    });
});
