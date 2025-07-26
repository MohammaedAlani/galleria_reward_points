<?php
// ============================================
// FIXED API ROUTES (routes/api.php)
// ============================================

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Authentication routes
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('logout', [AuthController::class, 'logout']);
    });

    Route::group(['middleware' => ['auth:sanctum']], function () {
        Route::post('user/create', [AuthController::class, 'create']);
        Route::get('user', [AuthController::class, 'index']);
        Route::delete('user/{user}', [AuthController::class, 'deleteUser']);
        Route::put('user/{user}', [AuthController::class, 'updateUser']);
        // ============================================
        // CUSTOMER ROUTES - CRITICAL: ORDER MATTERS!
        // Put ALL specific routes BEFORE apiResource
        // ============================================

        // Analytics routes (must come first)
        Route::get('customers/analytics', [CustomerController::class, 'analytics']);
        Route::get('customers/dashboard-stats', [CustomerController::class, 'dashboardStats']);
        Route::get('customers/trends', [CustomerController::class, 'trends']);

        Route::get('dashboard/stats', [DashboardController::class, 'stats']);
        Route::get('dashboard/analytics', [DashboardController::class, 'analytics']);


        // Export routes (must come before resource routes)
        Route::post('customers/export', [CustomerController::class, 'export']);
        Route::post('customers/export-custom', [CustomerController::class, 'exportCustom']);

        // Search routes
        Route::get('customers/search', [CustomerController::class, 'search']);
        Route::get('customers/suggestions', [CustomerController::class, 'suggestions']);
        Route::get('customers/find-duplicates', [CustomerController::class, 'findDuplicates']);

        // Bulk operation routes (CRITICAL - must come before {customer} routes)
        Route::post('customers/bulk-action', [CustomerController::class, 'bulkAction']);
        Route::post('customers/bulk-import', [CustomerController::class, 'bulkImport']);
        Route::post('customers/bulk-update', [CustomerController::class, 'bulkUpdate']);
        Route::post('customers/bulk-delete', [CustomerController::class, 'bulkDelete']);
        Route::post('customers/bulk-export', [CustomerController::class, 'bulkExport']);
        Route::get('customers/{customer}', [CustomerController::class, 'show']);
        // Validation routes
        Route::post('customers/validate-phone', [CustomerController::class, 'validatePhone']);
        Route::post('customers/validate-email', [CustomerController::class, 'validateEmail']);
//        Route::post('customers/validate-card', [CustomerController::class, 'validateCardNumber']);

        // Special operation routes with specific names
        Route::post('customers/merge-customers', [CustomerController::class, 'mergeCustomers']);

        // Individual customer specific routes (these use {customer} parameter)
        Route::get('customers/{customer}/timeline', [CustomerController::class, 'timeline']);

        Route::get('customers/{customer}/transactions', [CustomerController::class, 'customerTransactions']);
        Route::get('customers/{customer}/analytics', [CustomerController::class, 'customerAnalytics']);
        Route::post('customers/{customer}/archive', [CustomerController::class, 'archive']);
        Route::post('customers/{customer}/restore', [CustomerController::class, 'restore']);
        Route::post('customers/{customer}/add-points', [CustomerController::class, 'addPoints']);
        Route::post('customers/{customer}/deduct-points', [CustomerController::class, 'deductPoints']);

        // IMPORTANT: Resource routes MUST come LAST
        Route::apiResource('customers', CustomerController::class);

        // ============================================
        // TRANSACTION ROUTES
        // ============================================

        // Analytics and reporting
        Route::get('transactions/analytics', [TransactionController::class, 'analytics']);
        Route::get('transactions/export', [TransactionController::class, 'export']);
        Route::get('transactions/pending', [TransactionController::class, 'pendingTransactions']);

        // Bulk operations
        Route::post('transactions/bulk-approve', [TransactionController::class, 'bulkApprove']);
        Route::post('transactions/bulk-reject', [TransactionController::class, 'bulkReject']);

        // Individual transaction operations
        Route::post('transactions/{transaction}/approve', [TransactionController::class, 'approve']);
        Route::post('transactions/{transaction}/reject', [TransactionController::class, 'reject']);
        Route::post('transactions/{transaction}/cancel', [TransactionController::class, 'cancel']);

        // Transaction creation
        Route::post('transactions', [TransactionController::class, 'addTransaction']);
        Route::post('transactions/use', [TransactionController::class, 'useTransaction']);
        Route::post('transactions/return', [TransactionController::class, 'returnTransaction']);

        // Resource routes
        Route::get('transactions', [TransactionController::class, 'index']);
        Route::get('transactions/{transaction}', [TransactionController::class, 'show']);

        Route::post('transactions/bulk-action', [TransactionController::class, 'bulkAction']);
    });
});

// ============================================
// ALTERNATIVE APPROACH: Using Route::group with prefix
// This can help avoid conflicts
// ============================================

/*
Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {

    // Customer management routes
    Route::prefix('customers')->name('customers.')->group(function () {

        // Special routes first
        Route::get('analytics', [CustomerController::class, 'analytics'])->name('analytics');
        Route::get('export', [CustomerController::class, 'export'])->name('export');
        Route::get('search', [CustomerController::class, 'search'])->name('search');
        Route::get('find-duplicates', [CustomerController::class, 'findDuplicates'])->name('find-duplicates');

        // Bulk operations
        Route::post('bulk-action', [CustomerController::class, 'bulkAction'])->name('bulk-action');
        Route::post('bulk-export', [CustomerController::class, 'bulkExport'])->name('bulk-export');

        // Validation
        Route::post('validate-phone', [CustomerController::class, 'validatePhone'])->name('validate-phone');
        Route::post('validate-email', [CustomerController::class, 'validateEmail'])->name('validate-email');

        // Individual customer routes
        Route::get('{customer}/timeline', [CustomerController::class, 'timeline'])->name('timeline');
        Route::get('{customer}/analytics', [CustomerController::class, 'customerAnalytics'])->name('customer-analytics');

        // Standard CRUD
        Route::get('/', [CustomerController::class, 'index'])->name('index');
        Route::post('/', [CustomerController::class, 'store'])->name('store');
        Route::get('{customer}', [CustomerController::class, 'show'])->name('show');
        Route::put('{customer}', [CustomerController::class, 'update'])->name('update');
        Route::delete('{customer}', [CustomerController::class, 'destroy'])->name('destroy');
    });
});
*/

// ============================================
// DEBUGGING ROUTES
// Add this temporarily to debug route conflicts
// ============================================

if (config('app.debug')) {
    Route::get('debug/routes', function () {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri, 'customers')) {
                $routes[] = [
                    'method' => implode('|', $route->methods),
                    'uri' => $route->uri,
                    'name' => $route->getName(),
                    'action' => $route->getActionName(),
                ];
            }
        }
        return response()->json($routes);
    });
}
