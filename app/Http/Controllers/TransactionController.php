<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Carbon\Carbon;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TransactionController extends Controller
{
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
    private function enrichCustomerData($transaction){
        //            "id" => 38
//        "customer_id" => 12
//        "add_by" => "1"
//        "approved_by" => "1"
//        "transaction_type" => "use"
//        "transaction_date" => "2025-06-10 11:38:49"
//        "transaction_amount" => "1000"
//        "transaction_number" => "1231231231233"
//        "transaction_status" => "pending"
//        "created_at" => "2025-06-10 11:38:49"
//        "updated_at" => "2025-06-10 11:38:49"
        $addBy = User::find($transaction->add_by);
        $approvedBY =User::find($transaction->transaction_type);
        $customer = Customer::find($transaction->customer_id);
        if($addBy){
            $transaction->add_by = $addBy->name;
        }else{
            $transaction->add_by='--';
        }
            if ($customer){
                $transaction->customer_id = $customer->name;
            }else{
                $transaction->customer_id='--';
            }
        if($approvedBY){
            $transaction->approved_by = $approvedBY->name;
        }else{
            $transaction->approved_by='--';
        }
        $transaction->transaction_type  = $transaction->transaction_type == 'use' ?' استخدام  نقاط':'استرجاع';
        $transaction->transaction_date=$transaction->transaction_date? Carbon::parse($transaction->transaction_date)->diffInDays(Carbon::now()) : 0;
        $transaction->created_at=$transaction->created_at? Carbon::parse($transaction->created_at)->diffInDays(Carbon::now()) : 0;

        return $transaction;
    }

    private function prepareExportData($transactions)
    {
        $csvData = [];
        // Headers
        $csvData[] = [
            'اسم الزبون', 'نوع الحركة', 'المبلغ ', 'رقم الحركة',
            'تاريخ الحركة', 'اضيفت بواسطة', 'تمت الموافقة بواسطة ', 'حالة الحركة'
        ];

        // Data rows
        foreach ($transactions as $transaction) {
            $csvData[] = [
                $transaction->customer_id,
                $transaction->transaction_type,
                $transaction->transaction_amount,
                $transaction->transaction_number ,
                $transaction->transaction_date ,
                $transaction->add_by ,
                $transaction->approved_by ,
                $transaction->transaction_status ,
            ];
        }

        return $csvData;
    }
    private function performBulkExport($transactionIds)
    {
        try {
            // Get customers with their transaction counts
            $transactions = Transaction::whereIn('id', $transactionIds)
                ->get();

            if ($transactions->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'لا توجد زبائن للتصدير'
                ], 422);
            }

            // Enrich customer data
            $enrichedTransaction = $transactions->map(function ($transaction) {
                return $this->enrichCustomerData($transaction);
            });

            // Prepare CSV data
            $csvData = $this->prepareExportData($enrichedTransaction);

            Log::info('Bulk export completed', [
                'customer_count' => count($enrichedTransaction),
                'exported_by' => auth()->id()
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $csvData,
                'filename' => 'selected_transaction_' . date('Y-m-d_H-i-s') . '.csv',
                'total_records' => count($enrichedTransaction),
                'export_info' => [
                    'generated_at' => now()->toISOString(),
                    'generated_by' => auth()->user()->name ?? 'Unknown Transaction',
                    'customer_ids' => $transactionIds
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Bulk export error: ' . $e->getMessage(), [
                'customer_ids' => $transactionIds
            ]);
            throw $e;
        }
    }

    public function bulkAction(Request $request){
//        // Debug logging
        Log::info('Bulk action called', [
            'request_data' => $request->all(),
            'user_id' => auth()->id(),
            'route_name' => request()->route()->getName(),
            'route_uri' => request()->route()->uri()
        ]);
//        // Validate request
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:delete,export,archive,restore,update_status',
            'transaction_ids' => 'required|array|min:1|max:100',
            'transaction_ids.*' => 'integer|exists:transactions,id'
        ]);
//
        if ($validator->fails()) {
            Log::warning('Bulk action validation failed', [
                'errors' => $validator->errors(),
                'request' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'بيانات غير صحيحة',
                'errors' => $validator->errors()
            ], 422);
        }
//
        try {
//            // Check if customers exist
            $existingCustomers = Transaction::whereIn('id', $request->transaction_ids)->count();
            if ($existingCustomers !== count($request->transaction_ids)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'بعض الزبائن المحددين غير موجودين',
                    'found' => $existingCustomers,
                    'requested' => count($request->transaction_ids)
                ], 422);
            }
            switch ($request->action) {

                case 'export':
                    return $this->performBulkExport($request->transaction_ids);

                default:
                    return response()->json([
                        'status' => 'error',
                        'message' => 'إجراء غير مدعوم: ' . $request->action
                    ], 422);
            }

        } catch (\Exception $e) {
            Log::error('Bulk action error: ' . $e->getMessage(), [
                'action' => $request->action,
                'transaction_ids' => $request->transaction_ids,
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'فشل في تنفيذ العملية المجمعة: ' . $e->getMessage(),
                'debug' => config('app.debug') ? [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ] : null
            ], 500);
        }
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
    public function index(Request $request)
    {
        $query = Transaction::with([
            'customer:id,name,phone,address,card_number',
            'addByUser:id,name,email',
            'approvedByUser:id,name,email',
        ]);

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('transaction_status', $request->status);
        }

        // Filter by transaction type
        if ($request->has('type') && $request->type !== 'all') {
            $query->where('transaction_type', $request->type);
        }

        // Filter by date range
        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('transaction_date', '>=', $request->date_from);
        }

        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('transaction_date', '<=', $request->date_to);
        }

        // Filter by specific date
        if ($request->has('date') && $request->date) {
            $query->whereDate('transaction_date', $request->date);
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

        // Filter by customer ID
        if ($request->has('customer_id') && $request->customer_id) {
            $query->where('customer_id', $request->customer_id);
        }

        // Filter by amount range
        if ($request->has('amount_from') && $request->amount_from) {
            $query->where('transaction_amount', '>=', $request->amount_from);
        }

        if ($request->has('amount_to') && $request->amount_to) {
            $query->where('transaction_amount', '<=', $request->amount_to);
        }

        // Filter by staff member
        if ($request->has('staff_id') && $request->staff_id) {
            $query->where(function($q) use ($request) {
                $q->where('add_by', $request->staff_id)
                    ->orWhere('approved_by', $request->staff_id);
            });
        }

        // Sort options
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        $allowedSortFields = ['created_at', 'transaction_date', 'transaction_amount', 'updated_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        // Pagination
        $perPage = $request->get('per_page', 10);
        $perPage = min($perPage, 100); // Limit to 100 items per page

        $transactions = $query->paginate($perPage);

        // Add statistics
        $stats = $this->getTransactionStats($request);

        return response()->json([
            'status' => 'success',
            'data' => $transactions,
            'stats' => $stats,
        ]);
    }

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

    // ... rest of your existing methods remain the same ...
//    public function show()
//    {
//        dd('show');
//    }
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
