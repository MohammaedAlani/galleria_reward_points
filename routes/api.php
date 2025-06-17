<?php
// ============================================
// COMPLETE API ROUTES (routes/api.php)
// ============================================

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes
Route::prefix('v1')->group(function () {
    // Authentication routes
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('register', [AuthController::class, 'register']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);

        // Protected auth routes
        Route::middleware(['auth:sanctum'])->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('user', [AuthController::class, 'user']);
            Route::put('user', [AuthController::class, 'updateProfile']);
            Route::post('change-password', [AuthController::class, 'changePassword']);
        });
    });

    // Public API info
    Route::get('info', function () {
        return response()->json([
            'app_name' => config('app.name'),
            'version' => '1.0.0',
            'api_version' => 'v1',
            'status' => 'active',
            'timezone' => config('app.timezone'),
            'locale' => config('app.locale')
        ]);
    });
});

// Protected routes
Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {

    // User management routes
    Route::prefix('users')->group(function () {
        Route::get('/', [AuthController::class, 'index']);
        Route::post('/', [AuthController::class, 'create']);
        Route::get('{user}', [AuthController::class, 'show']);
        Route::put('{user}', [AuthController::class, 'update']);
        Route::delete('{user}', [AuthController::class, 'destroy']);
        Route::post('{user}/restore', [AuthController::class, 'restore']);
        Route::get('{user}/activity', [AuthController::class, 'getActivity']);
    });

    // ============================================
    // CUSTOMER ROUTES (Order is important!)
    // ============================================

    // Analytics and reporting routes (must come before resource routes)
    Route::get('customers/analytics', [CustomerController::class, 'analytics']);
    Route::get('customers/dashboard-stats', [CustomerController::class, 'dashboardStats']);
    Route::get('customers/trends', [CustomerController::class, 'trends']);
    Route::get('customers/segmentation', [CustomerController::class, 'segmentation']);

    // Export routes
    Route::get('customers/export', [CustomerController::class, 'export']);
    Route::post('customers/export-custom', [CustomerController::class, 'exportCustom']);
    Route::get('customers/export-template', [CustomerController::class, 'exportTemplate']);

    // Search and filtering routes
    Route::get('customers/search', [CustomerController::class, 'search']);
    Route::get('customers/suggestions', [CustomerController::class, 'suggestions']);
    Route::get('customers/find-duplicates', [CustomerController::class, 'findDuplicates']);

    // Bulk operations routes
    Route::post('customers/bulk-action', [CustomerController::class, 'bulkAction']);
    Route::post('customers/bulk-import', [CustomerController::class, 'bulkImport']);
    Route::post('customers/bulk-update', [CustomerController::class, 'bulkUpdate']);
    Route::post('customers/bulk-delete', [CustomerController::class, 'bulkDelete']);
    Route::post('customers/bulk-export', [CustomerController::class, 'bulkExport']);

    // Special operations routes
    Route::post('customers/merge', [CustomerController::class, 'mergeCustomers']);
    Route::post('customers/{customer}/archive', [CustomerController::class, 'archive']);
    Route::post('customers/{customer}/restore', [CustomerController::class, 'restore']);
    Route::get('customers/{customer}/timeline', [CustomerController::class, 'timeline']);
    Route::get('customers/{customer}/transactions', [CustomerController::class, 'customerTransactions']);
    Route::get('customers/{customer}/analytics', [CustomerController::class, 'customerAnalytics']);
    Route::post('customers/{customer}/add-points', [CustomerController::class, 'addPoints']);
    Route::post('customers/{customer}/deduct-points', [CustomerController::class, 'deductPoints']);
    Route::get('customers/{customer}/loyalty-summary', [CustomerController::class, 'loyaltySummary']);

    // Validation routes
    Route::post('customers/validate-phone', [CustomerController::class, 'validatePhone']);
    Route::post('customers/validate-email', [CustomerController::class, 'validateEmail']);
    Route::post('customers/validate-card', [CustomerController::class, 'validateCardNumber']);

    // Resource routes (must come LAST)
    Route::apiResource('customers', CustomerController::class);

    // ============================================
    // TRANSACTION ROUTES
    // ============================================

    // Analytics and reporting
    Route::get('transactions/analytics', [TransactionController::class, 'analytics']);
    Route::get('transactions/dashboard-stats', [TransactionController::class, 'dashboardStats']);
    Route::get('transactions/trends', [TransactionController::class, 'trends']);
    Route::get('transactions/summary', [TransactionController::class, 'summary']);

    // Export routes
    Route::get('transactions/export', [TransactionController::class, 'export']);
    Route::post('transactions/export-custom', [TransactionController::class, 'exportCustom']);

    // Special transaction routes
    Route::get('transactions/pending', [TransactionController::class, 'pendingTransactions']);
    Route::get('transactions/recent', [TransactionController::class, 'recentTransactions']);
    Route::get('transactions/by-customer/{customer}', [TransactionController::class, 'transactionsByCustomer']);

    // Approval routes
    Route::post('transactions/bulk-approve', [TransactionController::class, 'bulkApprove']);
    Route::post('transactions/bulk-reject', [TransactionController::class, 'bulkReject']);
    Route::post('transactions/{transaction}/approve', [TransactionController::class, 'approve']);
    Route::post('transactions/{transaction}/reject', [TransactionController::class, 'reject']);
    Route::post('transactions/{transaction}/cancel', [TransactionController::class, 'cancel']);

    // Transaction operations
    Route::post('transactions/add', [TransactionController::class, 'addTransaction']);
    Route::post('transactions/use', [TransactionController::class, 'useTransaction']);
    Route::post('transactions/return', [TransactionController::class, 'returnTransaction']);
    Route::post('transactions/transfer', [TransactionController::class, 'transferPoints']);

    // Resource routes
    Route::apiResource('transactions', TransactionController::class)->except(['update']);

    // ============================================
    // DASHBOARD ROUTES
    // ============================================

    Route::prefix('dashboard')->group(function () {
        Route::get('overview', [DashboardController::class, 'overview']);
        Route::get('stats', [DashboardController::class, 'stats']);
        Route::get('recent-activity', [DashboardController::class, 'recentActivity']);
        Route::get('alerts', [DashboardController::class, 'alerts']);
        Route::get('performance', [DashboardController::class, 'performance']);
        Route::get('trends', [DashboardController::class, 'trends']);
    });

    // ============================================
    // REPORT ROUTES
    // ============================================

    Route::prefix('reports')->group(function () {
        Route::get('/', [ReportController::class, 'index']);
        Route::post('generate', [ReportController::class, 'generate']);
        Route::get('templates', [ReportController::class, 'templates']);
        Route::get('customer-report', [ReportController::class, 'customerReport']);
        Route::get('transaction-report', [ReportController::class, 'transactionReport']);
        Route::get('loyalty-report', [ReportController::class, 'loyaltyReport']);
        Route::get('financial-report', [ReportController::class, 'financialReport']);
    });

    // ============================================
    // SYSTEM ROUTES
    // ============================================

    Route::prefix('system')->group(function () {
        Route::get('health', function () {
            return response()->json([
                'status' => 'healthy',
                'timestamp' => now(),
                'database' => DB::connection()->getPdo() ? 'connected' : 'disconnected',
                'cache' => Cache::has('health_check') ? 'working' : 'not working',
                'queue' => 'active'
            ]);
        });

        Route::get('config', function () {
            return response()->json([
                'points_per_iqd' => config('points.points_per_iqd'),
                'iqd_per_point' => config('points.iqd_per_point'),
                'min_transaction_amount' => config('points.min_transaction_amount'),
                'max_transaction_amount' => config('points.max_transaction_amount'),
                'auto_approve_add' => config('points.auto_approve_add_transactions'),
                'auto_approve_use' => config('points.auto_approve_use_transactions'),
            ]);
        });

        Route::middleware(['can:manage-system'])->group(function () {
            Route::post('cache/clear', function () {
                Artisan::call('cache:clear');
                return response()->json(['message' => 'Cache cleared successfully']);
            });

            Route::post('optimize', function () {
                Artisan::call('optimize');
                return response()->json(['message' => 'Application optimized successfully']);
            });
        });
    });
});

// Fallback route for API
Route::fallback(function () {
    return response()->json([
        'status' => 'error',
        'message' => 'API endpoint not found',
        'available_versions' => ['v1']
    ], 404);
});
