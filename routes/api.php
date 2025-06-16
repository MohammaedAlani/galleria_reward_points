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

        // ADD THESE NEW ROUTES FOR ENHANCED CUSTOMER FUNCTIONALITY:
        Route::get('customers/analytics', [CustomerController::class, 'analytics']);
        Route::get('customers/export', [CustomerController::class, 'export']);
        Route::post('customers/bulk-action', [CustomerController::class, 'bulkAction']);

        // Transaction routes (existing)
        Route::get('transactions', [TransactionController::class, 'index']);
        Route::get('transactions/pending', [TransactionController::class, 'pendingTransactions']);
        Route::post('transactions/approval/{transaction}/{status}', [TransactionController::class, 'approval']);
        Route::post('transactions/bulk-approve', [TransactionController::class, 'bulkApprove']);
        Route::post('transactions/bulk-reject', [TransactionController::class, 'bulkReject']);
        Route::post('transactions', [TransactionController::class, 'addTransaction']);
        Route::post('transactions/use', [TransactionController::class, 'useTransaction']);
        Route::post('transactions/return', [TransactionController::class, 'returnTransaction']);

        // ADD THESE NEW ROUTES FOR ENHANCED TRANSACTION FUNCTIONALITY:
        Route::get('transactions/analytics', [TransactionController::class, 'analytics']);
        Route::get('transactions/export', [TransactionController::class, 'export']);
    });
});

/*
 * IMPORTANT: Route Order Matters!
 *
 * Make sure to place specific routes BEFORE the resource routes to avoid conflicts.
 * For example, 'customers/analytics' should come before 'customers/{customer}'
 *
 * If you're getting route conflicts, rearrange like this:
 *
 * // Specific routes first
 * Route::get('customers/analytics', [CustomerController::class, 'analytics']);
 * Route::get('customers/export', [CustomerController::class, 'export']);
 * Route::post('customers/bulk-action', [CustomerController::class, 'bulkAction']);
 *
 * // Resource routes last
 * Route::apiResource('customers', CustomerController::class);
 */
