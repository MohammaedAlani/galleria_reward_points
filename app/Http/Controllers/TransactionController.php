<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $transactions = Transaction::with([
            'addByUser',
            'approvedByUser',
        ])->orderBy('created_at', 'desc')->paginate(10);

        return response()->json([
            'status' => 'success',
            'data' => $transactions,
        ]);
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

            // If approved, update the customer's points based on transaction type
            if ($status === 'approved') {
                $customer = $transaction->customer;

                switch ($transaction->transaction_type) {
                    case 'add':
                        // Add points to customer
                        $pointsToAdd = $transaction->transaction_amount * config('points.points_per_iqd');
                        $customer->total_points += $pointsToAdd;
                        $customer->last_transaction_date = now();
                        $customer->last_transaction_amount = $transaction->transaction_amount;
                        break;

                    case 'use':
                        // Deduct points from customer
                        $pointsToDeduct = $transaction->transaction_amount * config('points.points_per_use');

                        // Double-check if customer still has enough points
                        if ($customer->total_points_can_use < $pointsToDeduct) {
                            return response()->json([
                                'status' => 'error',
                                'message' => 'Not enough points to use.',
                            ], 400);
                        }

                        $customer->total_spent += $pointsToDeduct;
                        $customer->last_transaction_date = now();
                        $customer->last_transaction_amount = -$transaction->transaction_amount; // Negative for usage
                        break;

                    case 'return':
                        // This shouldn't happen as returns are auto-approved
                        // But handling it for completeness
                        $pointsToReturn = $transaction->transaction_amount * config('points.iqd_per_point');
                        $customer->total_points -= $pointsToReturn;
                        $customer->last_transaction_date = now();
                        $customer->last_transaction_amount = $transaction->transaction_amount;
                        break;
                }

                $customer->save();
            }

            return response()->json([
                'status' => 'success',
                'data' => $transaction,
            ]);
        });
    }

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
            $validatedData['transaction_status'] = 'pending';
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
     * Get pending transactions for approval
     */
    public function pendingTransactions()
    {
        $transactions = Transaction::where('transaction_status', 'pending')
            ->with(['customer', 'addByUser'])
            ->orderBy('created_at', 'asc')
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
}
