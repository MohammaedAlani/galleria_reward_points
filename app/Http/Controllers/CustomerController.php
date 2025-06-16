<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers with enhanced filtering and statistics
     */
    public function index(Request $request)
    {
        $query = Customer::query();

        // Apply filters
        $this->applyFilters($query, $request);

        // Apply sorting
        $this->applySorting($query, $request);

        // Pagination
        $perPage = min($request->get('per_page', 15), 100);
        $customers = $query->paginate($perPage);

        // Add calculated fields to each customer
        $customers->getCollection()->transform(function ($customer) {
            return $this->enrichCustomerData($customer);
        });

        // Get enhanced statistics
        $stats = $this->getCustomerStats($request);

        return response()->json([
            'status' => 'success',
            'data' => $customers,
            'stats' => $stats,
            'filters_applied' => $this->getAppliedFilters($request),
        ]);
    }

    /**
     * Display the specified customer with comprehensive information
     */
    public function show(Customer $customer)
    {
        // Load customer with all related data
        $customer = $customer->load([
            'transactions' => function($query) {
                $query->with(['addByUser:id,name', 'approvedByUser:id,name'])
                    ->orderBy('created_at', 'desc')
                    ->limit(50);
            }
        ]);

        // Enrich customer data
        $enrichedCustomer = $this->enrichCustomerData($customer);

        // Get customer analytics
        $analytics = $this->getCustomerAnalytics($customer->id);

        return response()->json([
            'status' => 'success',
            'data' => array_merge($enrichedCustomer->toArray(), [
                'analytics' => $analytics
            ]),
        ]);
    }

    /**
     * Store a newly created customer
     */
    public function store(StoreCustomerRequest $request)
    {
        DB::beginTransaction();
        try {
            // Check for duplicate phone or card number
            $existingCustomer = Customer::where('phone', $request->phone)
                ->orWhere('card_number', $request->card_number)
                ->first();

            if ($existingCustomer) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Customer with this phone number or card number already exists.',
                ], 422);
            }

            $customer = Customer::create([
                'name' => $request->name,
                'phone' => $request->phone,
                'address' => $request->address,
                'card_number' => $request->card_number,
                'date_of_birth' => $request->date_of_birth,
                'gender' => $request->gender,
                'notes' => $request->notes,
            ]);

            // Enrich the created customer data
            $enrichedCustomer = $this->enrichCustomerData($customer);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'data' => $enrichedCustomer,
                'message' => 'Customer created successfully.',
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create customer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the specified customer
     */
    public function update(Request $request, Customer $customer)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20|unique:customers,phone,' . $customer->id,
            'address' => 'nullable|string|max:255',
            'card_number' => 'nullable|string|max:50|unique:customers,card_number,' . $customer->id,
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female',
            'notes' => 'nullable|string|max:1000',
        ]);

        DB::beginTransaction();
        try {
            $customer->update($request->all());

            // Enrich the updated customer data
            $enrichedCustomer = $this->enrichCustomerData($customer->fresh());

            DB::commit();

            return response()->json([
                'status' => 'success',
                'data' => $enrichedCustomer,
                'message' => 'Customer updated successfully.',
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update customer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified customer
     */
    public function destroy(Customer $customer)
    {
        DB::beginTransaction();
        try {
            // Check if customer has any pending transactions
            $pendingTransactions = Transaction::where('customer_id', $customer->id)
                ->where('transaction_status', 'pending')
                ->count();

            if ($pendingTransactions > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete customer with pending transactions.',
                ], 422);
            }

            // Delete customer transactions (only approved/rejected ones)
            Transaction::where('customer_id', $customer->id)
                ->whereIn('transaction_status', ['approved', 'rejected', 'cancelled'])
                ->delete();

            // Delete the customer
            $customer->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Customer deleted successfully.',
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete customer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export customers data
     */
    public function export(Request $request)
    {
        $query = Customer::query();
        $this->applyFilters($query, $request);

        $customers = $query->get();
        $enrichedCustomers = $customers->map(function ($customer) {
            return $this->enrichCustomerData($customer);
        });

        $csvData = [];
        $csvData[] = [
            'ID', 'Name', 'Phone', 'Address', 'Card Number',
            'Total Points', 'Available Points', 'Used Points', 'Points Value (IQD)',
            'Total Transactions', 'Last Transaction Date', 'Last Transaction Amount',
            'Customer Status', 'Registration Date', 'Gender', 'Date of Birth', 'Notes'
        ];

        foreach ($enrichedCustomers as $customer) {
            $csvData[] = [
                $customer->id,
                $customer->name,
                $customer->phone,
                $customer->address ?? 'N/A',
                $customer->card_number ?? 'N/A',
                $customer->total_points,
                $customer->total_points_can_use,
                $customer->total_spent,
                $customer->points_amount,
                $customer->transaction_count,
                $customer->last_transaction_date ?? 'N/A',
                $customer->last_transaction_amount ?? 0,
                $customer->customer_status,
                $customer->created_at,
                $customer->gender ?? 'N/A',
                $customer->date_of_birth ?? 'N/A',
                $customer->notes ?? 'N/A',
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => $csvData,
            'filename' => 'customers_' . date('Y-m-d_H-i-s') . '.csv',
            'total_records' => count($enrichedCustomers)
        ]);
    }

    /**
     * Get customer analytics
     */
    public function analytics(Request $request)
    {
        $dateFrom = $request->get('date_from', Carbon::now()->subDays(30)->format('Y-m-d'));
        $dateTo = $request->get('date_to', Carbon::now()->format('Y-m-d'));

        // Customer registration trends
        $registrationTrends = Customer::selectRaw('
                DATE(created_at) as date,
                COUNT(*) as new_customers
            ')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Customer activity levels
        $activityLevels = Customer::selectRaw('
                CASE
                    WHEN DATEDIFF(NOW(), last_transaction_date) <= 30 THEN "active"
                    WHEN DATEDIFF(NOW(), last_transaction_date) <= 90 THEN "moderate"
                    ELSE "inactive"
                END as activity_level,
                COUNT(*) as count
            ')
            ->groupBy('activity_level')
            ->get();

        // Top customers by points
        $topCustomers = Customer::selectRaw('
                id, name, phone, total_points, total_points_can_use, total_spent
            ')
            ->orderByDesc('total_points')
            ->limit(10)
            ->get();

        // Points distribution
        $pointsDistribution = Customer::selectRaw('
                CASE
                    WHEN total_points_can_use = 0 THEN "0"
                    WHEN total_points_can_use <= 100 THEN "1-100"
                    WHEN total_points_can_use <= 500 THEN "101-500"
                    WHEN total_points_can_use <= 1000 THEN "501-1000"
                    ELSE "1000+"
                END as points_range,
                COUNT(*) as count
            ')
            ->groupBy('points_range')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'registration_trends' => $registrationTrends,
                'activity_levels' => $activityLevels,
                'top_customers' => $topCustomers,
                'points_distribution' => $pointsDistribution,
                'date_range' => [
                    'from' => $dateFrom,
                    'to' => $dateTo
                ]
            ]
        ]);
    }

    /**
     * Bulk operations on customers
     */
    public function bulkAction(Request $request)
    {
        $request->validate([
            'action' => 'required|in:delete,export,update_status',
            'customer_ids' => 'required|array|min:1',
            'customer_ids.*' => 'exists:customers,id'
        ]);

        switch ($request->action) {
            case 'delete':
                return $this->bulkDelete($request->customer_ids);
            case 'export':
                return $this->bulkExport($request->customer_ids);
            default:
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid action specified.'
                ], 422);
        }
    }

    // Private helper methods

    private function applyFilters($query, Request $request)
    {
        // Search filter
        if ($request->has('search') && $request->search) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('name', 'like', "%{$searchTerm}%")
                    ->orWhere('phone', 'like', "%{$searchTerm}%")
                    ->orWhere('address', 'like', "%{$searchTerm}%")
                    ->orWhere('card_number', 'like', "%{$searchTerm}%");
            });
        }

        // Status filter (active, inactive, moderate)
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
                        $q->where('last_transaction_date', '<', Carbon::now()->subDays(90))
                            ->orWhereNull('last_transaction_date');
                        break;
                }
            });
        }

        // Points range filter
        if ($request->has('points_min') && $request->points_min) {
            $query->where('total_points_can_use', '>=', $request->points_min);
        }

        if ($request->has('points_max') && $request->points_max) {
            $query->where('total_points_can_use', '<=', $request->points_max);
        }

        // Registration date filter
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
            'name', 'phone', 'created_at', 'last_transaction_date',
            'total_points', 'total_points_can_use', 'total_spent'
        ];

        if (in_array($sortBy, $allowedSortFields)) {
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

        // Get transaction count
        $customer->transaction_count = Transaction::where('customer_id', $customer->id)->count();

        // Calculate customer status
        $customer->customer_status = $this->calculateCustomerStatus($customer);

        // Calculate days since registration
        $customer->days_since_registration = $customer->created_at ?
            Carbon::parse($customer->created_at)->diffInDays(Carbon::now()) : 0;

        // Calculate age if date_of_birth is available
        $customer->age = $customer->date_of_birth ?
            Carbon::parse($customer->date_of_birth)->age : null;

        return $customer;
    }

    private function calculateCustomerStatus($customer)
    {
        if (!$customer->last_transaction_date) {
            return 'inactive';
        }

        $daysSinceLastTransaction = Carbon::parse($customer->last_transaction_date)->diffInDays(Carbon::now());

        if ($daysSinceLastTransaction <= 30) {
            return 'active';
        } elseif ($daysSinceLastTransaction <= 90) {
            return 'moderate';
        } else {
            return 'inactive';
        }
    }

    private function getCustomerStats(Request $request)
    {
        $baseQuery = Customer::query();
        $this->applyFilters($baseQuery, $request);

        // Basic counts
        $totalCustomers = (clone $baseQuery)->count();
        $activeCustomers = (clone $baseQuery)->where('last_transaction_date', '>=', Carbon::now()->subDays(30))->count();
        $newCustomers = (clone $baseQuery)->whereDate('created_at', '>=', Carbon::now()->subDays(30))->count();

        // Points statistics
        $pointsStats = (clone $baseQuery)->selectRaw('
            SUM(total_points) as total_points_sum,
            SUM(total_spent) as total_spent_sum,
            AVG(total_points) as avg_points,
            MAX(total_points) as max_points
        ')->first();

        // Activity distribution
        $activityDistribution = Customer::selectRaw('
                CASE
                    WHEN last_transaction_date >= ? THEN "active"
                    WHEN last_transaction_date >= ? THEN "moderate"
                    ELSE "inactive"
                END as status,
                COUNT(*) as count
            ', [Carbon::now()->subDays(30), Carbon::now()->subDays(90)])
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'overview' => [
                'total' => $totalCustomers,
                'active' => $activeCustomers,
                'inactive' => $totalCustomers - $activeCustomers,
                'new_this_month' => $newCustomers,
            ],
            'points' => [
                'total_points' => $pointsStats->total_points_sum ?? 0,
                'total_spent' => $pointsStats->total_spent_sum ?? 0,
                'average_points' => round($pointsStats->avg_points ?? 0, 2),
                'max_points' => $pointsStats->max_points ?? 0,
            ],
            'activity' => array_merge([
                'active' => 0,
                'moderate' => 0,
                'inactive' => 0,
            ], $activityDistribution),
        ];
    }

    private function getCustomerAnalytics($customerId)
    {
        $customer = Customer::find($customerId);

        // Transaction history summary
        $transactionSummary = Transaction::where('customer_id', $customerId)
            ->selectRaw('
                transaction_type,
                COUNT(*) as count,
                SUM(transaction_amount) as total_amount,
                AVG(transaction_amount) as avg_amount
            ')
            ->groupBy('transaction_type')
            ->get();

        // Monthly transaction trends
        $monthlyTrends = Transaction::where('customer_id', $customerId)
            ->selectRaw('
                DATE_FORMAT(transaction_date, "%Y-%m") as month,
                COUNT(*) as transaction_count,
                SUM(CASE WHEN transaction_status = "approved" THEN transaction_amount ELSE 0 END) as approved_amount
            ')
            ->groupBy('month')
            ->orderBy('month')
            ->limit(12)
            ->get();

        return [
            'transaction_summary' => $transactionSummary,
            'monthly_trends' => $monthlyTrends,
            'total_value' => $customer->total_points * config('points.iqd_per_point', 100),
        ];
    }

    private function getAppliedFilters(Request $request)
    {
        $filters = [];

        $filterKeys = ['search', 'status', 'points_min', 'points_max', 'date_from', 'date_to', 'gender'];

        foreach ($filterKeys as $key) {
            if ($request->has($key) && $request->get($key) !== '' && $request->get($key) !== 'all') {
                $filters[$key] = $request->get($key);
            }
        }

        return $filters;
    }

    private function bulkDelete($customerIds)
    {
        $results = [
            'successful' => [],
            'failed' => [],
        ];

        foreach ($customerIds as $customerId) {
            try {
                $customer = Customer::findOrFail($customerId);

                // Check for pending transactions
                $pendingCount = Transaction::where('customer_id', $customerId)
                    ->where('transaction_status', 'pending')
                    ->count();

                if ($pendingCount > 0) {
                    $results['failed'][] = [
                        'id' => $customerId,
                        'name' => $customer->name,
                        'reason' => 'Has pending transactions'
                    ];
                    continue;
                }

                // Delete transactions and customer
                Transaction::where('customer_id', $customerId)->delete();
                $customer->delete();

                $results['successful'][] = [
                    'id' => $customerId,
                    'name' => $customer->name
                ];

            } catch (\Exception $e) {
                $results['failed'][] = [
                    'id' => $customerId,
                    'reason' => $e->getMessage()
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Bulk delete completed',
            'results' => $results,
        ]);
    }

    private function bulkExport($customerIds)
    {
        $customers = Customer::whereIn('id', $customerIds)->get();
        $enrichedCustomers = $customers->map(function ($customer) {
            return $this->enrichCustomerData($customer);
        });

        return response()->json([
            'status' => 'success',
            'data' => $enrichedCustomers,
            'filename' => 'selected_customers_' . date('Y-m-d_H-i-s') . '.csv',
        ]);
    }
}
