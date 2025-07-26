<?php

namespace App\Helper;

use App\Models\Customer;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class Helper
{
    private function applyFilters($query, Request $request)
    {
        // Search filter
        if ($request->has('search') && $request->search) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('name', 'like', "%{$searchTerm}%")
                    ->orWhere('phone', 'like', "%{$searchTerm}%")
                    ->orWhere('address', 'like', "%{$searchTerm}%")
                    ->orWhere('card_number', 'like', "%{$searchTerm}%")
                    ->orWhere('email', 'like', "%{$searchTerm}%")
                    ->orWhere('notes', 'like', "%{$searchTerm}%");
            });
        }

        // Status filter
        if ($request->has('status') && $request->status !== 'all') {
            $status = $request->status;
            $query->where(function($q) use ($status) {
                switch ($status) {
                    case 'active':
                        $q->where('last_transaction_date', '>=', Carbon::now()->subDays(30));
                        break;
                    case 'moderate':
                        $q->whereBetween('last_transaction_date', [
                            Carbon::now()->subDays(90),
                            Carbon::now()->subDays(30)
                        ]);
                        break;
                    case 'inactive':
                        $q->where(function($subQ) {
                            $subQ->where('last_transaction_date', '<', Carbon::now()->subDays(90))
                                ->orWhereNull('last_transaction_date');
                        });
                        break;
                }
            });
        }

        // Points range filters
        if ($request->has('points_min') && is_numeric($request->points_min)) {
            $query->whereRaw('(total_points - total_spent) >= ?', [$request->points_min]);
        }

        if ($request->has('points_max') && is_numeric($request->points_max)) {
            $query->whereRaw('(total_points - total_spent) <= ?', [$request->points_max]);
        }

        // Date filters
        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Gender filter
        if ($request->has('gender') && $request->gender !== 'all') {
            $query->where('gender', $request->gender);
        }

        return $query;
    }

    private function applySorting($query, Request $request)
    {
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        $allowedSortFields = [
            'name', 'phone', 'email', 'created_at', 'updated_at',
            'last_transaction_date', 'total_points', 'total_spent'
        ];

        if ($sortBy === 'total_points_can_use') {
            $query->orderByRaw('(total_points - total_spent) ' . $sortOrder);
        } elseif (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        return $query;
    }

    private function enrichCustomerData($customer)
    {
        // Calculate additional fields
        $customer->total_points_can_use = max(0, $customer->total_points - $customer->total_spent);
        $customer->points_amount = $customer->total_points_can_use * config('points.iqd_per_point', 100);

        // Get transaction count if not already loaded
        if (!isset($customer->transaction_count)) {
            $customer->transaction_count = Transaction::where('customer_id', $customer->id)->count();
        }

        // Calculate customer status
        $customer->customer_status = $this->calculateCustomerStatus($customer);

        // Calculate days since registration
        $customer->days_since_registration = $customer->created_at ?
            Carbon::parse($customer->created_at)->diffInDays(Carbon::now()) : 0;

        // Calculate age if date_of_birth is available
        $customer->age = $customer->date_of_birth ?
            Carbon::parse($customer->date_of_birth)->age : null;

        // Calculate utilization rate
        $customer->utilization_rate = $customer->total_points > 0 ?
            round(($customer->total_spent / $customer->total_points) * 100, 1) : 0;

        return $customer;
    }

    private function calculateCustomerStatus($customer)
    {
        if (!$customer->last_transaction_date) {
            return 'غير نشط';
        }

        $daysSinceLastTransaction = Carbon::parse($customer->last_transaction_date)->diffInDays(Carbon::now());

        if ($daysSinceLastTransaction <= 7) {
            return 'نشط جداً';
        } elseif ($daysSinceLastTransaction <= 30) {
            return 'نشط';
        } elseif ($daysSinceLastTransaction <= 90) {
            return 'متوسط النشاط';
        } else {
            return 'غير نشط';
        }
    }

    private function getCustomerStats(Request $request)
    {
        $cacheKey = 'customer_stats_' . md5(serialize($request->all()));

        return Cache::remember($cacheKey, 300, function () use ($request) {
            $baseQuery = Customer::query();
            $this->applyFilters($baseQuery, $request);

            // Basic counts with optimized queries
            $stats = DB::select("
                SELECT
                    COUNT(*) as total_customers,
                    COUNT(CASE WHEN last_transaction_date >= ? THEN 1 END) as active_customers,
                    COUNT(CASE WHEN created_at >= ? THEN 1 END) as new_customers,
                    COUNT(CASE WHEN last_transaction_date IS NULL THEN 1 END) as never_active,
                    SUM(total_points) as total_points_sum,
                    SUM(total_spent) as total_spent_sum,
                    AVG(total_points) as avg_points,
                    MAX(total_points) as max_points
                FROM customers
                WHERE deleted_at IS NULL
            ", [
                Carbon::now()->subDays(30),
                Carbon::now()->subDays(30)
            ]);

            $mainStats = $stats[0];

            return [
                'overview' => [
                    'total' => (int) $mainStats->total_customers,
                    'active' => (int) $mainStats->active_customers,
                    'inactive' => (int) ($mainStats->total_customers - $mainStats->active_customers),
                    'new_this_month' => (int) $mainStats->new_customers,
                ],
                'points' => [
                    'total_points' => (int) ($mainStats->total_points_sum ?? 0),
                    'total_spent' => (int) ($mainStats->total_spent_sum ?? 0),
                    'average_points' => round($mainStats->avg_points ?? 0, 2),
                    'max_points' => (int) ($mainStats->max_points ?? 0),
                ],
            ];
        });
    }

    private function getCustomerAnalytics($customerId)
    {
        $customer = Customer::find($customerId);

        // Transaction summary
        $transactionSummary = Transaction::where('customer_id', $customerId)
            ->selectRaw('
                transaction_type,
                COUNT(*) as count,
                SUM(transaction_amount) as total_amount,
                AVG(transaction_amount) as avg_amount
            ')
            ->groupBy('transaction_type')
            ->get();

        // Monthly trends
        $monthlyTrends = Transaction::where('customer_id', $customerId)
            ->selectRaw('
                DATE_FORMAT(transaction_date, "%Y-%m") as month,
                COUNT(*) as transaction_count,
                SUM(CASE WHEN transaction_status = "approved" THEN transaction_amount ELSE 0 END) as approved_amount
            ')
            ->groupBy('month')
            ->orderBy('month', 'desc')
            ->limit(12)
            ->get();

        return [
            'transaction_summary' => $transactionSummary,
            'monthly_trends' => $monthlyTrends,
            'total_value' => $customer->total_points * config('points.iqd_per_point', 100),
        ];
    }

    private function getCustomerTimeline($customerId, $limit = 20)
    {
        return Transaction::where('customer_id', $customerId)
            ->with(['addByUser:id,name', 'approvedByUser:id,name'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($transaction) {
                return [
                    'id' => $transaction->id,
                    'type' => $transaction->transaction_type,
                    'amount' => $transaction->transaction_amount,
                    'status' => $transaction->transaction_status,
                    'date' => $transaction->created_at,
                    'added_by' => $transaction->addByUser->name ?? 'غير محدد',
                    'approved_by' => $transaction->approvedByUser->name ?? null,
                    'description' => $this->getTransactionDescription($transaction)
                ];
            });
    }

    private function getTransactionDescription($transaction)
    {
        $descriptions = [
            'add' => 'إضافة نقاط',
            'use' => 'استخدام نقاط',
            'return' => 'إرجاع نقاط'
        ];

        return $descriptions[$transaction->transaction_type] ?? $transaction->transaction_type;
    }

    private function createInitialTransaction($customer, $points)
    {
        Transaction::create([
            'customer_id' => $customer->id,
            'transaction_type' => 'add',
            'transaction_amount' => $points,
            'transaction_number' => 'INIT-' . $customer->id . '-' . time(),
            'transaction_status' => 'approved',
            'transaction_date' => now(),
            'add_by' => auth()->id(),
            'approved_by' => auth()->id(),
            'notes' => 'نقاط تسجيل أولية'
        ]);

        $customer->update([
            'last_transaction_date' => now(),
            'last_transaction_amount' => $points
        ]);
    }

    private function checkForDuplicates($request)
    {
        // Check phone
        if ($request->phone) {
            $phoneExists = Customer::where('phone', $request->phone)->exists();
            if ($phoneExists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'رقم الهاتف مسجل مسبقاً',
                    'field' => 'phone'
                ], 422);
            }
        }

        // Check email
        if ($request->email) {
            $emailExists = Customer::where('email', $request->email)->exists();
            if ($emailExists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'البريد الإلكتروني مسجل مسبقاً',
                    'field' => 'email'
                ], 422);
            }
        }

        // Check card number
        if ($request->card_number) {
            $cardExists = Customer::where('card_number', $request->card_number)->exists();
            if ($cardExists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'رقم البطاقة مسجل مسبقاً',
                    'field' => 'card_number'
                ], 422);
            }
        }

        return null;
    }

    private function getAppliedFilters(Request $request)
    {
        $filters = [];
        $filterKeys = [
            'search', 'status', 'points_min', 'points_max',
            'date_from', 'date_to', 'gender'
        ];

        foreach ($filterKeys as $key) {
            if ($request->has($key) && $request->get($key) !== '' && $request->get($key) !== 'all') {
                $filters[$key] = $request->get($key);
            }
        }

        return $filters;
    }

    private function prepareExportData($customers)
    {
        $csvData = [];

        // Headers
        $csvData[] = [
            'رقم الزبون', 'الاسم', 'رقم الهاتف', 'البريد الإلكتروني',
            'العنوان', 'رقم البطاقة', 'الجنس', 'تاريخ الميلاد', 'العمر',
            'إجمالي النقاط', 'النقاط المتاحة', 'النقاط المستخدمة',
            'قيمة النقاط (د.ع)', 'عدد المعاملات', 'حالة الزبون',
            'تاريخ التسجيل', 'آخر معاملة', 'أيام منذ التسجيل'
        ];

        // Data rows
        foreach ($customers as $customer) {
            $csvData[] = [
                $customer->id,
                $customer->name,
                $customer->phone,
                $customer->email ?? 'غير محدد',
                $customer->address ?? 'غير محدد',
                $customer->card_number ?? 'غير محدد',
                $customer->gender === 'male' ? 'ذكر' : ($customer->gender === 'female' ? 'أنثى' : 'غير محدد'),
                $customer->date_of_birth ?? 'غير محدد',
                $customer->age ?? 'غير محدد',
                $customer->total_points,
                $customer->total_points_can_use,
                $customer->total_spent,
                $customer->points_amount,
                $customer->transaction_count,
                $customer->customer_status,
                $customer->created_at->format('Y-m-d'),
                $customer->last_transaction_date ? $customer->last_transaction_date->format('Y-m-d') : 'لا توجد',
                $customer->days_since_registration
            ];
        }

        return $csvData;
    }

    private function clearCustomerCaches()
    {
        Cache::forget('customer_analytics_*');
        Cache::forget('customer_stats_*');
        Cache::forget('dashboard_stats');
    }

}
