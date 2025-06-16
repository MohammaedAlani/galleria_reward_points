<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    /**
     * Show the form for approval a new resource.
     */
//    public function approval(Transaction $transaction, $status)
//    {
//        // Validate the status
//        if (!in_array($status, ['approved', 'rejected'])) {
//            return response()->json([
//                'status' => 'error',
//                'message' => 'Invalid status provided. Use "approved" or "rejected".',
//            ], 400);
//        }
//
//        // Check if the transaction is already approved or rejected
//        if ($transaction->transaction_status !== 'pending') {
//            return response()->json([
//                'status' => 'error',
//                'message' => 'Transaction has already been processed.',
//            ], 400);
//        }
//
//        return DB::transaction(function () use ($transaction, $status) {
//            // Update the transaction status
//            $transaction->update([
//                'transaction_status' => $status,
//                'approved_by' => auth()->id(),
//            ]);
//
//            // If approved, update the customer's points based on transaction type
//            if ($status === 'approved') {
//                $customer = $transaction->customer;
//
//                switch ($transaction->transaction_type) {
//                    case 'add':
//                        // Add points to customer
//                        $pointsToAdd = $transaction->transaction_amount * config('points.points_per_iqd');
//                        $customer->total_points += $pointsToAdd;
//                        $customer->last_transaction_date = now();
//                        $customer->last_transaction_amount = $transaction->transaction_amount;
//                        break;
//
//                    case 'use':
//                        // Deduct points from customer
//                        $pointsToDeduct = $transaction->transaction_amount * config('points.points_per_use');
//
//                        // Double-check if customer still has enough points
//                        if ($customer->total_points_can_use < $pointsToDeduct) {
//                            return response()->json([
//                                'status' => 'error',
//                                'message' => 'Not enough points to use.',
//                            ], 400);
//                        }
//
//                        $customer->total_spent += $pointsToDeduct;
//                        $customer->last_transaction_date = now();
//                        $customer->last_transaction_amount = -$transaction->transaction_amount; // Negative for usage
//                        break;
//
//                    case 'return':
//                        // This shouldn't happen as returns are auto-approved
//                        // But handling it for completeness
//                        $pointsToReturn = $transaction->transaction_amount * config('points.iqd_per_point');
//                        $customer->total_points -= $pointsToReturn;
//                        $customer->last_transaction_date = now();
//                        $customer->last_transaction_amount = $transaction->transaction_amount;
//                        break;
//                }
//
//                $customer->save();
//            }
//
//            return response()->json([
//                'status' => 'success',
//                'data' => $transaction,
//            ]);
//        });
//    }

    /**
     * Add transaction (purchase) - Auto approved
     */
    public function addTransaction(Request $request)
    {
        $validatedData = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'transaction_amount' => 'required|numeric|min:0',
            'transaction_number' => 'required|string|unique:transactions,transaction_number',
        ]);

        return DB::transaction(function () use ($validatedData) {
            $userId = auth()->id();
            $validatedData['add_by'] = $userId;
            $validatedData['transaction_status'] = 'approved';
            $validatedData['transaction_type'] = 'add';
            $validatedData['approved_by'] = $userId;
            $validatedData['transaction_date'] = now();

            $transaction = Transaction::create($validatedData);

            // Update customer points immediately since it's auto-approved
            $customer = $transaction->customer;
            $pointsPerIqd = config('points.points_per_iqd');

            $pointsToAdd = $transaction->transaction_amount * $pointsPerIqd;
            $customer->total_points += $pointsToAdd;
            $customer->last_transaction_date = now();
            $customer->last_transaction_amount = $transaction->transaction_amount;
            $customer->save();

            return response()->json([
                'status' => 'success',
                'data' => $transaction,
            ]);
        });
    }

    /**
     * Use transaction (redemption) - Requires approval
     */
    public function useTransaction(Request $request)
    {
        $validatedData = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'transaction_amount' => 'required|numeric|min:0',
            'transaction_number' => 'required|string',
        ]);

        return DB::transaction(function () use ($validatedData) {
            $userId = auth()->id();
            $validatedData['add_by'] = $userId;
            $validatedData['transaction_status'] = 'approved'; // Set to approved for now, will be updated during approval
            $validatedData['transaction_type'] = 'use';
            // Don't set approved_by yet - will be set during approval
            $validatedData['transaction_date'] = now();

            // Check if customer has enough points before creating transaction
            $customer = \App\Models\Customer::find($validatedData['customer_id']);
            $pointsPerIqd = config('points.points_per_use');
            // 2000 / 100 = 20 points
            $requiredPoints = $validatedData['transaction_amount'] * $pointsPerIqd;

            if ($customer->total_points_can_use < $requiredPoints) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Not enough points to use. Required: ' . $requiredPoints . ', Available: ' . $customer->total_points_can_use,
                ], 400);
            }

            $customer->total_spent += $requiredPoints;
            $customer->save();

            $transaction = Transaction::create($validatedData);

            return response()->json([
                'status' => 'success',
                'data' => $transaction,
                'message' => 'Transaction created successfully. Waiting for approval.',
            ]);
        });
    }

    /**
     * Return transaction (refund) - Auto approved
     */
    public function returnTransaction(Request $request)
    {
        $validatedData = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'transaction_amount' => 'required|numeric|min:0',
            'transaction_number' => 'required|string',
            'original_transaction_id' => 'nullable|exists:transactions,id', // Optional reference to original transaction
        ]);

        return DB::transaction(function () use ($validatedData) {
            $userId = auth()->id();
            $validatedData['add_by'] = $userId;
            $validatedData['transaction_status'] = 'approved';
            $validatedData['transaction_type'] = 'return';
            $validatedData['approved_by'] = $userId;
            $validatedData['transaction_date'] = now();

            $transaction = Transaction::create($validatedData);

            // Update customer points immediately since it's auto-approved
            $customer = $transaction->customer;
            $pointsPerIqd = config('points.points_per_iqd');

            // Calculate points to add back (return gives points back)
            $pointsToReturn = $transaction->transaction_amount * $pointsPerIqd;

            $customer->total_points -= $pointsToReturn;
            $customer->last_transaction_date = now();
            $customer->last_transaction_amount = $transaction->transaction_amount;
            $customer->save();

            return response()->json([
                'status' => 'success',
                'data' => $transaction,
            ]);
        });
    }

    /**
     * Get customer's transaction history
     */
    public function customerTransactions(Request $request, $customerId)
    {
        $transactions = Transaction::where('customer_id', $customerId)
            ->with(['addByUser', 'approvedByUser'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json([
            'status' => 'success',
            'data' => $transactions,
        ]);
    }

    /**
     * Cancel a pending transaction
     */
    public function cancelTransaction(Transaction $transaction)
    {
        if ($transaction->transaction_status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Only pending transactions can be cancelled.',
            ], 400);
        }

        $transaction->update([
            'transaction_status' => 'cancelled',
            'approved_by' => auth()->id(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Transaction cancelled successfully.',
            'data' => $transaction,
        ]);
    }





    /**
     * Display a listing of the resource with filtering support.
     */
    /**
     * Display a listing of transactions with enhanced filtering
     */
    public function index(Request $request)
    {
        $query = Transaction::with([
            'customer:id,name,phone,address,card_number',
            'addByUser:id,name,email',
            'approvedByUser:id,name,email',
        ]);

        // Enhanced filtering
        $this->applyFilters($query, $request);

        // Sorting with validation
        $this->applySorting($query, $request);

        // Pagination
        $perPage = min($request->get('per_page', 15), 100);
        $transactions = $query->paginate($perPage);

        // Enhanced statistics
        $stats = $this->getEnhancedStats($request);

        return response()->json([
            'status' => 'success',
            'data' => $transactions,
            'stats' => $stats,
            'filters_applied' => $this->getAppliedFilters($request),
        ]);
    }

    /**
     * Get enhanced transaction statistics
     */
    private function getEnhancedStats(Request $request)
    {
        $baseQuery = Transaction::query();
        $this->applyFilters($baseQuery, $request, false); // Don't apply status filter for stats

        // Today's stats
        $todayQuery = (clone $baseQuery)->whereDate('transaction_date', Carbon::today());

        // This week's stats
        $weekQuery = (clone $baseQuery)->whereBetween('transaction_date', [
            Carbon::now()->startOfWeek(),
            Carbon::now()->endOfWeek()
        ]);

        // This month's stats
        $monthQuery = (clone $baseQuery)->whereMonth('transaction_date', Carbon::now()->month)
            ->whereYear('transaction_date', Carbon::now()->year);

        return [
            'overview' => [
                'total' => $baseQuery->count(),
                'pending' => (clone $baseQuery)->where('transaction_status', 'pending')->count(),
                'approved' => (clone $baseQuery)->where('transaction_status', 'approved')->count(),
                'rejected' => (clone $baseQuery)->where('transaction_status', 'rejected')->count(),
                'cancelled' => (clone $baseQuery)->where('transaction_status', 'cancelled')->count(),
            ],
            'amounts' => [
                'total_amount' => (clone $baseQuery)->where('transaction_status', 'approved')->sum('transaction_amount'),
                'pending_amount' => (clone $baseQuery)->where('transaction_status', 'pending')->sum('transaction_amount'),
                'approved_amount' => (clone $baseQuery)->where('transaction_status', 'approved')->sum('transaction_amount'),
                'average_transaction' => (clone $baseQuery)->where('transaction_status', 'approved')->avg('transaction_amount'),
            ],
            'types' => [
                'add' => (clone $baseQuery)->where('transaction_type', 'add')->count(),
                'use' => (clone $baseQuery)->where('transaction_type', 'use')->count(),
                'return' => (clone $baseQuery)->where('transaction_type', 'return')->count(),
                'debit' => (clone $baseQuery)->where('transaction_type', 'debit')->count(),
            ],
            'periods' => [
                'today' => [
                    'count' => $todayQuery->count(),
                    'amount' => $todayQuery->where('transaction_status', 'approved')->sum('transaction_amount'),
                ],
                'week' => [
                    'count' => $weekQuery->count(),
                    'amount' => $weekQuery->where('transaction_status', 'approved')->sum('transaction_amount'),
                ],
                'month' => [
                    'count' => $monthQuery->count(),
                    'amount' => $monthQuery->where('transaction_status', 'approved')->sum('transaction_amount'),
                ],
            ],
            'processing_stats' => [
                'approval_rate' => $this->getApprovalRate($baseQuery),
                'avg_processing_time' => $this->getAverageProcessingTime($baseQuery),
                'pending_aging' => $this->getPendingAging(),
            ]
        ];
    }

    /**
     * Apply filters to query
     */
    private function applyFilters($query, Request $request, $includeStatus = true)
    {
        // Status filter
        if ($includeStatus && $request->has('status') && $request->status !== 'all') {
            $query->where('transaction_status', $request->status);
        }

        // Transaction type filter
        if ($request->has('type') && $request->type !== 'all') {
            $query->where('transaction_type', $request->type);
        }

        // Date range filters
        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('transaction_date', '>=', $request->date_from);
        }

        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('transaction_date', '<=', $request->date_to);
        }

        // Amount range filters
        if ($request->has('amount_from') && $request->amount_from) {
            $query->where('transaction_amount', '>=', $request->amount_from);
        }

        if ($request->has('amount_to') && $request->amount_to) {
            $query->where('transaction_amount', '<=', $request->amount_to);
        }

        // Search functionality
        if ($request->has('search') && $request->search) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('transaction_number', 'like', "%{$searchTerm}%")
                    ->orWhereHas('customer', function($customerQuery) use ($searchTerm) {
                        $customerQuery->where('name', 'like', "%{$searchTerm}%")
                            ->orWhere('phone', 'like', "%{$searchTerm}%")
                            ->orWhere('card_number', 'like', "%{$searchTerm}%");
                    })
                    ->orWhereHas('addByUser', function($userQuery) use ($searchTerm) {
                        $userQuery->where('name', 'like', "%{$searchTerm}%");
                    });
            });
        }

        // Customer filter
        if ($request->has('customer_id') && $request->customer_id) {
            $query->where('customer_id', $request->customer_id);
        }

        // Staff filter
        if ($request->has('staff_id') && $request->staff_id) {
            $query->where(function($q) use ($request) {
                $q->where('add_by', $request->staff_id)
                    ->orWhere('approved_by', $request->staff_id);
            });
        }

        return $query;
    }

    /**
     * Apply sorting to query
     */
    private function applySorting($query, Request $request)
    {
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        $allowedSortFields = [
            'created_at', 'transaction_date', 'transaction_amount',
            'updated_at', 'transaction_number', 'transaction_status'
        ];

        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        return $query;
    }

    /**
     * Get transaction analytics
     */
    public function analytics(Request $request)
    {
        $dateFrom = $request->get('date_from', Carbon::now()->subDays(30)->format('Y-m-d'));
        $dateTo = $request->get('date_to', Carbon::now()->format('Y-m-d'));

        // Daily transaction trends
        $dailyTrends = Transaction::selectRaw('
                DATE(transaction_date) as date,
                COUNT(*) as total_transactions,
                SUM(CASE WHEN transaction_status = "approved" THEN transaction_amount ELSE 0 END) as approved_amount,
                COUNT(CASE WHEN transaction_status = "pending" THEN 1 END) as pending_count,
                COUNT(CASE WHEN transaction_status = "approved" THEN 1 END) as approved_count,
                COUNT(CASE WHEN transaction_status = "rejected" THEN 1 END) as rejected_count
            ')
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Transaction type distribution
        $typeDistribution = Transaction::selectRaw('
                transaction_type,
                COUNT(*) as count,
                SUM(CASE WHEN transaction_status = "approved" THEN transaction_amount ELSE 0 END) as total_amount
            ')
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->groupBy('transaction_type')
            ->get();

        // Top customers by transaction volume
        $topCustomers = Transaction::selectRaw('
                customer_id,
                COUNT(*) as transaction_count,
                SUM(CASE WHEN transaction_status = "approved" THEN transaction_amount ELSE 0 END) as total_amount
            ')
            ->with('customer:id,name,phone')
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->where('transaction_status', 'approved')
            ->groupBy('customer_id')
            ->orderByDesc('total_amount')
            ->limit(10)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'daily_trends' => $dailyTrends,
                'type_distribution' => $typeDistribution,
                'top_customers' => $topCustomers,
                'date_range' => [
                    'from' => $dateFrom,
                    'to' => $dateTo
                ]
            ]
        ]);
    }

    /**
     * Export transactions
     */
    public function export(Request $request)
    {
        $query = Transaction::with(['customer', 'addByUser', 'approvedByUser']);
        $this->applyFilters($query, $request);

        $transactions = $query->get();

        $csvData = [];
        $csvData[] = [
            'Transaction Number', 'Customer Name', 'Customer Phone', 'Type',
            'Amount', 'Status', 'Date', 'Added By', 'Approved By', 'Created At'
        ];

        foreach ($transactions as $transaction) {
            $csvData[] = [
                $transaction->transaction_number,
                $transaction->customer->name ?? 'N/A',
                $transaction->customer->phone ?? 'N/A',
                $transaction->transaction_type,
                $transaction->transaction_amount,
                $transaction->transaction_status,
                $transaction->transaction_date,
                $transaction->addByUser->name ?? 'N/A',
                $transaction->approvedByUser->name ?? 'N/A',
                $transaction->created_at,
            ];
        }

        $filename = 'transactions_' . date('Y-m-d_H-i-s') . '.csv';

        return response()->json([
            'status' => 'success',
            'data' => $csvData,
            'filename' => $filename,
            'total_records' => count($transactions)
        ]);
    }

    // Helper methods
    private function validateTransactionForApproval($transaction)
    {
        if ($transaction->transaction_type === 'use') {
            $requiredPoints = $transaction->transaction_amount * config('points.points_per_use');
            if ($transaction->customer->total_points_can_use < $requiredPoints) {
                return [
                    'valid' => false,
                    'reason' => 'Insufficient points. Required: ' . $requiredPoints . ', Available: ' . $transaction->customer->total_points_can_use
                ];
            }
        }

        return ['valid' => true, 'reason' => null];
    }

    private function getApprovalRate($baseQuery)
    {
        $total = (clone $baseQuery)->whereIn('transaction_status', ['approved', 'rejected'])->count();
        $approved = (clone $baseQuery)->where('transaction_status', 'approved')->count();

        return $total > 0 ? round(($approved / $total) * 100, 2) : 0;
    }

    private function getAverageProcessingTime($baseQuery)
    {
        $processed = (clone $baseQuery)
            ->whereIn('transaction_status', ['approved', 'rejected'])
            ->whereNotNull('approved_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, approved_at)) as avg_minutes')
            ->first();

        return $processed ? round($processed->avg_minutes, 2) : 0;
    }

    private function getPendingAging()
    {
        return Transaction::where('transaction_status', 'pending')
            ->selectRaw('
                COUNT(CASE WHEN TIMESTAMPDIFF(HOUR, created_at, NOW()) <= 24 THEN 1 END) as within_24h,
                COUNT(CASE WHEN TIMESTAMPDIFF(HOUR, created_at, NOW()) BETWEEN 24 AND 72 THEN 1 END) as within_72h,
                COUNT(CASE WHEN TIMESTAMPDIFF(HOUR, created_at, NOW()) > 72 THEN 1 END) as over_72h
            ')
            ->first();
    }

    private function getAppliedFilters(Request $request)
    {
        $filters = [];

        if ($request->has('status') && $request->status !== 'all') {
            $filters['status'] = $request->status;
        }

        if ($request->has('type') && $request->type !== 'all') {
            $filters['type'] = $request->type;
        }

        if ($request->has('date_from') && $request->date_from) {
            $filters['date_from'] = $request->date_from;
        }

        if ($request->has('date_to') && $request->date_to) {
            $filters['date_to'] = $request->date_to;
        }

        return $filters;
    }

    private function reversePointDeduction($transaction)
    {
        $customer = $transaction->customer;
        $pointsToReverse = $transaction->transaction_amount * config('points.points_per_use');

        $customer->total_spent -= $pointsToReverse;
        $customer->save();
    }

//    private function updateCustomerPoints($transaction, $status)
//    {
//        if ($status !== 'approved') {
//            return;
//        }
//
//        $customer = $transaction->customer;
//
//        switch ($transaction->transaction_type) {
//            case 'add':
//                $pointsToAdd = $transaction->transaction_amount * config('points.points_per_iqd');
//                $customer->total_points += $pointsToAdd;
//                $customer->last_transaction_date = now();
//                $customer->last_transaction_amount = $transaction->transaction_amount;
//                break;
//
//            case 'use':
//                // Points were already deducted when transaction was created
//                $customer->last_transaction_date = now();
//                $customer->last_transaction_amount = -$transaction->transaction_amount;
//                break;
//
//            case 'return':
//                $pointsToReturn = $transaction->transaction_amount * config('points.points_per_iqd');
//                $customer->total_points += $pointsToReturn;
//                if ($customer->total_spent >= $transaction->transaction_amount) {
//                    $customer->total_spent -= $transaction->transaction_amount;
//                }
//                $customer->last_transaction_date = now();
//                $customer->last_transaction_amount = $transaction->transaction_amount;
//                break;
//        }
//
//        $customer->save();
//    }


    /**
     * Get transaction statistics based on current filters
     */
    private function getTransactionStats(Request $request)
    {
        $query = Transaction::query();

        // Apply same filters as main query (excluding status filter for stats)
        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('transaction_date', '>=', $request->date_from);
        }

        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('transaction_date', '<=', $request->date_to);
        }

        if ($request->has('date') && $request->date) {
            $query->whereDate('transaction_date', $request->date);
        }

        if ($request->has('customer_id') && $request->customer_id) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('type') && $request->type !== 'all') {
            $query->where('transaction_type', $request->type);
        }

        return [
            'total' => $query->count(),
            'pending' => (clone $query)->where('transaction_status', 'pending')->count(),
            'approved' => (clone $query)->where('transaction_status', 'approved')->count(),
            'rejected' => (clone $query)->where('transaction_status', 'rejected')->count(),
            'cancelled' => (clone $query)->where('transaction_status', 'cancelled')->count(),
            'total_amount' => (clone $query)->where('transaction_status', 'approved')->sum('transaction_amount'),
            'pending_amount' => (clone $query)->where('transaction_status', 'pending')->sum('transaction_amount'),
            'types' => [
                'add' => (clone $query)->where('transaction_type', 'add')->count(),
                'use' => (clone $query)->where('transaction_type', 'use')->count(),
                'return' => (clone $query)->where('transaction_type', 'return')->count(),
            ]
        ];
    }

    /**
     * Get pending transactions for approval
     */
    public function pendingTransactions(Request $request)
    {
        $query = Transaction::where('transaction_status', 'pending')
            ->with(['customer:id,name,phone,address,card_number', 'addByUser:id,name,email']);

        // Apply filters for pending transactions
        if ($request->has('date') && $request->date) {
            $query->whereDate('transaction_date', $request->date);
        }

        if ($request->has('type') && $request->type !== 'all') {
            $query->where('transaction_type', $request->type);
        }

        if ($request->has('search') && $request->search) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('transaction_number', 'like', "%{$searchTerm}%")
                    ->orWhereHas('customer', function($customerQuery) use ($searchTerm) {
                        $customerQuery->where('name', 'like', "%{$searchTerm}%");
                    });
            });
        }

        $transactions = $query->orderBy('created_at', 'asc')->paginate(10);

        return response()->json([
            'status' => 'success',
            'data' => $transactions,
        ]);
    }

    /**
     * Bulk approve transactions
     */
    public function bulkApprove(Request $request)
    {
        $request->validate([
            'transaction_ids' => 'required|array',
            'transaction_ids.*' => 'exists:transactions,id'
        ]);

        $successCount = 0;
        $errors = [];

        DB::transaction(function () use ($request, &$successCount, &$errors) {
            foreach ($request->transaction_ids as $transactionId) {
                try {
                    $transaction = Transaction::findOrFail($transactionId);

                    if ($transaction->transaction_status !== 'pending') {
                        $errors[] = "Transaction {$transaction->transaction_number} is not pending";
                        continue;
                    }

                    $transaction->update([
                        'transaction_status' => 'approved',
                        'approved_by' => auth()->id(),
                    ]);

                    // Update customer points
                    $this->updateCustomerPoints($transaction, 'approved');
                    $successCount++;

                } catch (\Exception $e) {
                    $errors[] = "Failed to approve transaction {$transactionId}: " . $e->getMessage();
                }
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => "Successfully approved {$successCount} transactions",
            'approved_count' => $successCount,
            'errors' => $errors,
        ]);
    }

    /**
     * Bulk reject transactions
     */
    public function bulkReject(Request $request)
    {
        $request->validate([
            'transaction_ids' => 'required|array',
            'transaction_ids.*' => 'exists:transactions,id'
        ]);

        $rejectedCount = Transaction::whereIn('id', $request->transaction_ids)
            ->where('transaction_status', 'pending')
            ->update([
                'transaction_status' => 'rejected',
                'approved_by' => auth()->id(),
            ]);

        return response()->json([
            'status' => 'success',
            'message' => "Successfully rejected {$rejectedCount} transactions",
            'rejected_count' => $rejectedCount,
        ]);
    }

    /**
     * Update customer points based on transaction
     */
    private function updateCustomerPoints($transaction, $status)
    {
        if ($status !== 'approved') {
            return;
        }

        $customer = $transaction->customer;

        switch ($transaction->transaction_type) {
            case 'add':
                $pointsToAdd = $transaction->transaction_amount * config('points.points_per_iqd');
                $customer->total_points += $pointsToAdd;
                $customer->last_transaction_date = now();
                $customer->last_transaction_amount = $transaction->transaction_amount;
                break;

            case 'use':
                $pointsToDeduct = $transaction->transaction_amount * config('points.points_per_iqd');

                if ($customer->total_points_can_use < $pointsToDeduct) {
                    throw new \Exception('Not enough points to use.');
                }

                $customer->total_points -= $pointsToDeduct;
                $customer->total_spent += $transaction->transaction_amount;
                $customer->last_transaction_date = now();
                $customer->last_transaction_amount = -$transaction->transaction_amount;
                break;

            case 'return':
                $pointsToReturn = $transaction->transaction_amount * config('points.points_per_iqd');
                $customer->total_points += $pointsToReturn;
                $customer->total_spent -= $transaction->transaction_amount;
                $customer->last_transaction_date = now();
                $customer->last_transaction_amount = $transaction->transaction_amount;
                break;
        }

        $customer->save();
    }

    /**
     * Show the form for approval a new resource.
     */
    public function approval(Transaction $transaction, $status)
    {
        // Validate the status
        if (!in_array($status, ['approved', 'rejected'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid status provided. Use "approved" or "rejected".',
            ], 400);
        }

        // Check if the transaction is already approved or rejected
        if ($transaction->transaction_status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction has already been processed.',
            ], 400);
        }

        return DB::transaction(function () use ($transaction, $status) {
            // Update the transaction status
            $transaction->update([
                'transaction_status' => $status,
                'approved_by' => auth()->id(),
            ]);

            // If approved, update customer points
            if ($status === 'approved') {
                $this->updateCustomerPoints($transaction, $status);
            }

            return response()->json([
                'status' => 'success',
                'data' => $transaction,
            ]);
        });
    }
}
