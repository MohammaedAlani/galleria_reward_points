<?php

namespace App\Http\Controllers;

use App\Exports\CustomersExport;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers with enhanced filtering and statistics
     */
    public function index(Request $request)
    {
        try {
            $startTime = microtime(true);

            $query = Customer::query();

            // Apply filters
            $this->applyFilters($query, $request);

            // Apply sorting
            $this->applySorting($query, $request);

            // Check if only stats requested
            if ($request->get('stats_only')) {
                return response()->json([
                    'status' => 'success',
                    'stats' => $this->getCustomerStats($request),
                ]);
            }

            // Pagination
            $perPage = min($request->get('per_page', 15), 100);
            $customers = $query->paginate($perPage);

            // Add calculated fields to each customer
            $customers->getCollection()->transform(function ($customer) {
                return $this->enrichCustomerData($customer);
            });

            // Get enhanced statistics
            $stats = $this->getCustomerStats($request);

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            return response()->json([
                'status' => 'success',
                'data' => $customers,
                'stats' => $stats,
                'filters_applied' => $this->getAppliedFilters($request),
                'meta' => [
                    'execution_time_ms' => $executionTime,
                    'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    'total_queries' => DB::getQueryLog() ? count(DB::getQueryLog()) : 0,
                    'cache_hits' => 0 // Implement if using query caching
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Customer index error: ' . $e->getMessage(), [
                'request' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'خطأ في جلب بيانات الزبائن',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Display the specified customer with comprehensive information
     */
    public function show(Customer $customer)
    {
        try {
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

            // Get customer activity timeline
            $timeline = $this->getCustomerTimeline($customer->id);

            return response()->json([
                'status' => 'success',
                'data' => array_merge($enrichedCustomer->toArray(), [
                    'analytics' => $analytics,
                    'timeline' => $timeline
                ]),
            ]);

        } catch (\Exception $e) {
            Log::error('Customer show error: ' . $e->getMessage(), [
                'customer_id' => $customer->id ?? null
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'خطأ في جلب بيانات الزبون'
            ], 500);
        }
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
     * Update the specified customer with enhanced validation
     */
    public function update(Request $request, Customer $customer)
    {
        DB::beginTransaction();
        try {
            $oldData = $customer->toArray();

            $customer->update([
                'name' => trim($request->name),
                'phone' => trim($request->phone),
                'email' => $request->email ? trim($request->email) : null,
                'address' => $request->address ? trim($request->address) : null,
                'card_number' => $request->card_number ? trim($request->card_number) : null,
                'date_of_birth' => $request->date_of_birth,
                'gender' => $request->gender,
                'notes' => $request->notes,
            ]);

            // Log significant changes
            $changes = array_diff_assoc($customer->toArray(), $oldData);
            if (!empty($changes)) {
                Log::info('Customer updated', [
                    'customer_id' => $customer->id,
                    'updated_by' => auth()->id(),
                    'changes' => array_keys($changes)
                ]);
            }

            // Enrich the updated customer data
            $enrichedCustomer = $this->enrichCustomerData($customer->fresh());

            DB::commit();

            // Clear related caches
            $this->clearCustomerCaches();

            return response()->json([
                'status' => 'success',
                'data' => $enrichedCustomer,
                'message' => 'تم تحديث بيانات الزبون بنجاح',
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Customer update error: ' . $e->getMessage(), [
                'customer_id' => $customer->id,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'فشل في تحديث بيانات الزبون: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified customer with enhanced checks
     */
    public function destroy(Customer $customer)
    {
        DB::beginTransaction();
        try {
            // Enhanced deletion checks
            $pendingTransactions = Transaction::where('customer_id', $customer->id)
                ->where('transaction_status', 'pending')
                ->count();

            if ($pendingTransactions > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "لا يمكن حذف الزبون لوجود {$pendingTransactions} معاملة معلقة",
                ], 422);
            }

            // Check if customer has high value
//            $pointsThreshold = config('points.high_value_customer_threshold', 5000);
//            if ($customer->total_points >= $pointsThreshold) {
//                return response()->json([
//                    'status' => 'error',
//                    'message' => 'لا يمكن حذف زبون ذو نقاط عالية بدون موافقة إدارية',
//                ], 422);
//            }

            // Store customer data for logging
            $customerData = $customer->toArray();

            // Soft delete transactions first
            Transaction::where('customer_id', $customer->id)->delete();

            // Delete the customer
            $customer->delete();

            // Log deletion
            Log::warning('Customer deleted', [
                'customer_data' => $customerData,
                'deleted_by' => auth()->id(),
                'deletion_time' => now()
            ]);

            DB::commit();

            // Clear related caches
            $this->clearCustomerCaches();

            return response()->json([
                'status' => 'success',
                'message' => 'تم حذف الزبون بنجاح',
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Customer deletion error: ' . $e->getMessage(), [
                'customer_id' => $customer->id,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'فشل في حذف الزبون: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search customers with advanced filters
     */
    public function search(Request $request)
    {
        try {
            $query = Customer::query();

            // Apply search term
            if ($request->has('q') && $request->q) {
                $searchTerm = $request->q;
                $query->where(function($q) use ($searchTerm) {
                    $q->where('name', 'like', "%{$searchTerm}%")
                        ->orWhere('phone', 'like', "%{$searchTerm}%")
                        ->orWhere('card_number', 'like', "%{$searchTerm}%");
                });
            }

            // Limit results for performance
            $limit = min($request->get('limit', 20), 50);
            $customers = $query->limit($limit)->get();

            // Transform for display
            $transformedCustomers = $customers->map(function ($customer) {
                return [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'card_number' => $customer->card_number,
                    'total_points' => $customer->total_points,
                    'status' => $this->calculateCustomerStatus($customer),
                    'avatar' => $customer->name ? strtoupper(substr($customer->name, 0, 1)) : 'Z'
                ];
            });

            return response()->json([
                'status' => 'success',
                'data' => $transformedCustomers,
                'count' => $transformedCustomers->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Customer search error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'خطأ في البحث',
                'data' => []
            ], 500);
        }
    }

    /**
     * Get customer suggestions for autocomplete
     */
    public function suggestions(Request $request)
    {
        try {
            $query = $request->get('q', '');

            if (strlen($query) < 2) {
                return response()->json([
                    'status' => 'success',
                    'data' => []
                ]);
            }

            $customers = Customer::where('name', 'like', "%{$query}%")
                ->orWhere('phone', 'like', "%{$query}%")
                ->limit(10)
                ->get(['id', 'name', 'phone']);

            return response()->json([
                'status' => 'success',
                'data' => $customers
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => []
            ]);
        }
    }

    /**
     * Export customers data with enhanced formatting
     */
    public function export(Request $request)
    {

        try {
            set_time_limit(300); // 5 minutes for large exports
            ini_set('memory_limit', '512M');

            $query = Customer::query();
            $this->applyFilters($query, $request);

            // Limit export size for performance
            $maxExportSize = config('points.max_export_records', 10000);
            $totalCount = $query->count();

            if ($totalCount > $maxExportSize) {
                return response()->json([
                    'status' => 'error',
                    'message' => "حجم التصدير كبير جداً ({$totalCount} سجل). الحد الأقصى هو {$maxExportSize} سجل",
                ], 422);
            }

            // Get customers with transaction counts
            $customers = $query->withCount('transactions')->get();

            $enrichedCustomers = $customers->map(function ($customer) {
                return $this->enrichCustomerData($customer);
            });

            // Prepare export info
            $exportInfo = [
                'generated_at' => now()->toISOString(),
                'generated_by' => auth()->user()->name ?? 'مستخدم غير معروف',
                'filters_applied' => $this->getAppliedFilters($request),
                'total_records' => count($enrichedCustomers)
            ];

            $filename = 'customers_export_' . date('Y-m-d_H-i-s') . '.xlsx';

            // Log export activity
            Log::info('Customer export completed', [
                'exported_by' => auth()->id(),
                'record_count' => count($enrichedCustomers),
                'filters_applied' => $this->getAppliedFilters($request)
            ]);

            // Store file temporarily
            $filePath = 'exports/' . $filename;
            Excel::store(new CustomersExport($enrichedCustomers, $exportInfo), $filePath, 'public');

            return response()->json([
                'status' => 'success',
                'download_url' => route('download.export', ['filename' => $filename]),
                'filename' => $filename,
                'total_records' => count($enrichedCustomers),
                'export_info' => $exportInfo
            ]);

        } catch (\Exception $e) {
            Log::error('Customer export error: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'filters' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'فشل في تصدير البيانات: ' . $e->getMessage(),
            ], 500);
        }
//        try {
//            set_time_limit(300); // 5 minutes for large exports
//            ini_set('memory_limit', '512M');
//
//            $query = Customer::query();
//            $this->applyFilters($query, $request);
//
//            // Limit export size for performance
//            $maxExportSize = config('points.max_export_records', 10000);
//            $totalCount = $query->count();
//
//            if ($totalCount > $maxExportSize) {
//                return response()->json([
//                    'status' => 'error',
//                    'message' => "حجم التصدير كبير جداً ({$totalCount} سجل). الحد الأقصى هو {$maxExportSize} سجل",
//                ], 422);
//            }
//
//            // Get customers with transaction counts
//            $customers = $query->withCount('transactions')->get();
//
//            $enrichedCustomers = $customers->map(function ($customer) {
//                return $this->enrichCustomerData($customer);
//            });
//
//            // Enhanced CSV headers
//            $csvData = $this->prepareExportData($enrichedCustomers);
//
//            // Log export activity
//            Log::info('Customer export completed', [
//                'exported_by' => auth()->id(),
//                'record_count' => count($enrichedCustomers),
//                'filters_applied' => $this->getAppliedFilters($request)
//            ]);
//
//            return response()->json([
//                'status' => 'success',
//                'data' => $csvData,
//                'filename' => 'customers_export_' . date('Y-m-d_H-i-s') . '.xlsx',
//                'total_records' => count($enrichedCustomers),
//                'export_info' => [
//                    'generated_at' => now()->toISOString(),
//                    'generated_by' => auth()->user()->name ?? 'مستخدم غير معروف',
//                    'filters_applied' => $this->getAppliedFilters($request)
//                ]
//            ]);
//
//        } catch (\Exception $e) {
//            Log::error('Export error: ' . $e->getMessage(), [
//                'user_id' => auth()->id(),
//                'filters' => $request->all()
//            ]);
//
//            return response()->json([
//                'status' => 'error',
//                'message' => 'فشل في تصدير البيانات: ' . $e->getMessage(),
//            ], 500);
//        }
    }

    /**
     * Get comprehensive customer analytics
     */
    public function analytics(Request $request)
    {
        try {
            $cacheKey = 'customer_analytics_' . md5(serialize($request->all()));

            return Cache::remember($cacheKey, 300, function () use ($request) {
                $dateFrom = $request->get('date_from', Carbon::now()->subDays(30)->format('Y-m-d'));
                $dateTo = $request->get('date_to', Carbon::now()->format('Y-m-d'));

                $analytics = [
//                    'overview' => $this->getAnalyticsOverview($dateFrom, $dateTo),
//                    'registration_trends' => $this->getRegistrationTrends($dateFrom, $dateTo),
//                    'activity_levels' => $this->getActivityLevels(),
//                    'top_customers' => $this->getTopCustomers(),
//                    'points_distribution' => $this->getPointsDistribution(),
//                    'geographic_distribution' => $this->getGeographicDistribution(),
//                    'demographic_analysis' => $this->getDemographicAnalysis(),
//                    'behavioral_insights' => $this->getBehavioralInsights()
                ];

                return response()->json([
                    'status' => 'success',
                    'data' => $analytics
                ]);
            });

        } catch (\Exception $e) {
            Log::error('Analytics error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'فشل في جلب التحليلات',
            ], 500);
        }
    }

    /**
     * Dashboard stats for quick overview
     */
    public function dashboardStats()
    {
        return Cache::remember('customer_dashboard_stats', 300, function () {
            $totalCustomers = Customer::count();
            $activeCustomers = Customer::where('last_transaction_date', '>=', Carbon::now()->subDays(30))->count();
            $newThisMonth = Customer::whereDate('created_at', '>=', Carbon::now()->startOfMonth())->count();
            $totalPoints = Customer::sum('total_points');
            $availablePoints = Customer::selectRaw('SUM(total_points - total_spent) as available')->first()->available ?? 0;

            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_customers' => $totalCustomers,
                    'active_customers' => $activeCustomers,
                    'new_this_month' => $newThisMonth,
                    'inactive_customers' => $totalCustomers - $activeCustomers,
                    'total_points' => $totalPoints,
                    'available_points' => $availablePoints,
                    'points_value_iqd' => $availablePoints * config('points.iqd_per_point', 100)
                ]
            ]);
        });
    }


    /**
     * Enhanced bulk action method with debugging
     */
    public function bulkAction(Request $request)
    {

        // Debug logging
        Log::info('Bulk action called', [
            'request_data' => $request->all(),
            'user_id' => auth()->id(),
            'route_name' => request()->route()->getName(),
            'route_uri' => request()->route()->uri()
        ]);

        // Validate request
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:delete,export,archive,restore,update_status',
            'customer_ids' => 'required|array|min:1|max:100',
            'customer_ids.*' => 'integer|exists:customers,id'
        ]);

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

        try {
            // Check if customers exist
            $existingCustomers = Customer::whereIn('id', $request->customer_ids)->count();
            if ($existingCustomers !== count($request->customer_ids)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'بعض الزبائن المحددين غير موجودين',
                    'found' => $existingCustomers,
                    'requested' => count($request->customer_ids)
                ], 422);
            }

            switch ($request->action) {
                case 'delete':
                    return $this->performBulkDelete($request->customer_ids);

                case 'export':
                    return $this->performBulkExport($request->customer_ids);

                default:
                    return response()->json([
                        'status' => 'error',
                        'message' => 'إجراء غير مدعوم: ' . $request->action
                    ], 422);
            }

        } catch (\Exception $e) {
            Log::error('Bulk action error: ' . $e->getMessage(), [
                'action' => $request->action,
                'customer_ids' => $request->customer_ids,
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
     * Perform bulk delete operation
     */
    private function performBulkDelete($customerIds)
    {
        $results = [
            'successful' => [],
            'failed' => [],
            'skipped' => []
        ];

        DB::beginTransaction();
        try {
            foreach ($customerIds as $customerId) {
                try {
                    $customer = Customer::findOrFail($customerId);

                    // Check for pending transactions
                    $pendingCount = Transaction::where('customer_id', $customerId)
                        ->where('transaction_status', 'pending')
                        ->count();

                    if ($pendingCount > 0) {
                        $results['skipped'][] = [
                            'id' => $customerId,
                            'name' => $customer->name,
                            'reason' => "يحتوي على {$pendingCount} معاملة معلقة"
                        ];
                        continue;
                    }

                    // Check high value customer
                    $pointsThreshold = config('points.high_value_customer_threshold', 5000);
                    if ($customer->total_points >= $pointsThreshold) {
                        $results['skipped'][] = [
                            'id' => $customerId,
                            'name' => $customer->name,
                            'reason' => 'زبون ذو قيمة عالية (يتطلب موافقة إدارية)'
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

                    Log::info('Customer deleted via bulk action', [
                        'customer_id' => $customerId,
                        'customer_name' => $customer->name,
                        'deleted_by' => auth()->id()
                    ]);

                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'id' => $customerId,
                        'reason' => $e->getMessage()
                    ];

                    Log::error('Failed to delete customer in bulk action', [
                        'customer_id' => $customerId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            DB::commit();

            // Clear caches
            $this->clearCustomerCaches();

            return response()->json([
                'status' => 'success',
                'message' => 'تم إكمال عملية الحذف المجمع',
                'results' => $results,
                'summary' => [
                    'total_requested' => count($customerIds),
                    'successful' => count($results['successful']),
                    'failed' => count($results['failed']),
                    'skipped' => count($results['skipped'])
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * Perform bulk export operation
     */
    private function performBulkExport($customerIds)
    {
        try {
            // Get customers with their transaction counts
            $customers = Customer::whereIn('id', $customerIds)
                ->withCount('transactions')
                ->get();

            if ($customers->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'لا توجد زبائن للتصدير'
                ], 422);
            }

            // Enrich customer data
            $enrichedCustomers = $customers->map(function ($customer) {
                return $this->enrichCustomerData($customer);
            });

            // Prepare CSV data
            $csvData = $this->prepareExportData($enrichedCustomers);

            Log::info('Bulk export completed', [
                'customer_count' => count($enrichedCustomers),
                'exported_by' => auth()->id()
            ]);
            return response()->json([
                'status' => 'success',
                'data' => $csvData,
                'filename' => 'selected_customers_' . date('Y-m-d_H-i-s') . '.xlsx',
                'total_records' => count($enrichedCustomers),
                'export_info' => [
                    'generated_at' => now()->toISOString(),
                    'generated_by' => auth()->user()->name ?? 'Unknown User',
                    'customer_ids' => $customerIds
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Bulk export error: ' . $e->getMessage(), [
                'customer_ids' => $customerIds
            ]);
            throw $e;
        }
    }

    /**
     * Test endpoint to verify bulk action is working
     */
    public function testBulkAction()
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Bulk action endpoint is working correctly',
            'timestamp' => now(),
            'user' => auth()->user()->name ?? 'Unknown',
            'route_info' => [
                'name' => request()->route()->getName(),
                'uri' => request()->route()->uri(),
                'methods' => request()->route()->methods()
            ]
        ]);
    }
    private function bulkDelete($customerIds)
    {
        $results = ['successful' => [], 'failed' => []];

        DB::transaction(function () use ($customerIds, &$results) {
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
                            'reason' => 'يحتوي على معاملات معلقة'
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
        });

        return response()->json([
            'status' => 'success',
            'message' => 'تم إكمال عملية الحذف المجمع',
            'results' => $results
        ]);
    }
    private function bulkExport($customerIds)
    {
        try {
            $customers = Customer::whereIn('id', $customerIds)
                ->withCount('transactions')
                ->get();

            $enrichedCustomers = $customers->map(function ($customer) {
                return $this->enrichCustomerData($customer);
            });

            $csvData = $this->prepareExportData($enrichedCustomers);

            return response()->json([
                'status' => 'success',
                'data' => $csvData,
                'filename' => 'selected_customers_' . date('Y-m-d_H-i-s') . '.xlsx',
                'total_records' => count($enrichedCustomers)
            ]);

        } catch (\Exception $e) {
            throw $e;
        }
    }









    private function applyFilters($query, Request $request)
    {
        // Search filter
        if ($request->has('search') && $request->search) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('name', 'like', "%{$searchTerm}%")
                    ->orWhere('phone', 'like', "%{$searchTerm}%")
                    ->orWhere('address', 'like', "%{$searchTerm}%");
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
