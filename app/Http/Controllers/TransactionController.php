<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddTransactionRequest;
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

        // Update the transaction status
        $transaction->update([
            'transaction_status' => $status,
            'approved_by' => auth()->id(),
        ]);

        // If approved, update the customer's points
        if ($status === 'approved') {
            $customer = $transaction->customer;
            $pointRate = config('points.points_per_iqd');

            // Calculate points to add
            $pointsToAdd = $transaction->transaction_amount * $pointRate;

            // Update customer's total points
            $customer->total_points += $pointsToAdd;
            $customer->last_transaction_date = now();
            $customer->last_transaction_amount = $transaction->transaction_amount;
            $customer->save();
        }

        return response()->json([
            'status' => 'success',
            'data' => $transaction,
        ]);
    }

    public function addTransaction(Request $request)
    {
        $validatedData = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'transaction_amount' => 'required|string',
            'transaction_number' => 'required|string',
        ]);

        return DB::transaction(function () use ($validatedData) {
            $userId = auth()->id();
            $validatedData['add_by'] = $userId;
            $validatedData['transaction_status'] = 'approved';
            $validatedData['transaction_type'] = 'add';
            $validatedData['approved_by'] = $userId;
            $validatedData['transaction_date'] = now();

            $transaction = Transaction::create($validatedData);

            $customer = $transaction->customer;
            $pointRate = config('points.iqd_per_point');

            $customer->total_points += ($transaction->transaction_amount * $pointRate);
            $customer->last_transaction_date = now();
            $customer->last_transaction_amount = $transaction->transaction_amount;
            $customer->save();

            return response()->json([
                'status' => 'success',
                'data' => $transaction,
            ]);
        });
    }

    public function useTransaction(Request $request)
    {
        $validatedData = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'transaction_amount' => 'required|string',
            'transaction_number' => 'required|string',
        ]);

        return DB::transaction(function () use ($validatedData) {
            $userId = auth()->id();
            $validatedData['add_by'] = $userId;
            $validatedData['transaction_status'] = 'pending';
            $validatedData['transaction_type'] = 'use';
            $validatedData['approved_by'] = $userId;
            $validatedData['transaction_date'] = now();

            $transaction = Transaction::create($validatedData);

            $customer = $transaction->customer;
            $pointRate = config('points.points_per_iqd');

            if ($customer->total_points < ($transaction->transaction_amount * $pointRate)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Not enough points to use.',
                ], 400);
            }

            return response()->json([
                'status' => 'success',
                'data' => $transaction,
            ]);
        });
    }
}
