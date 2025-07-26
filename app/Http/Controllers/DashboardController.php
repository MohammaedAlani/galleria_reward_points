<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats()
    {
        $users= DB::table('users')
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(role = 'admin') as admins"),
                DB::raw("SUM(role = 'accountant') as accountants"),
                DB::raw("SUM(role = 'cashier') as cashiers")
            )
            ->first();

        $customers = [
            'total' => Customer::count(),
            'active' => Customer::where('total_points', '>', 0)->count(),
            'inactive' => Customer::where('total_points', '<=', 0)->count(),
            'new_today' => Customer::whereDate('created_at', today())->count(),
            'new_this_week' => Customer::whereBetween('created_at', [now()->startOfWeek(), now()])->count(),
            'new_this_month' => Customer::whereMonth('created_at', now()->month)->count(),
        ];

        $transactions = Transaction::selectRaw("
            COUNT(*) as total,
            SUM(transaction_status = 'pending') as pending,
            SUM(transaction_status = 'approved') as approved,
            SUM(transaction_status = 'rejected') as rejected
        ")->first();

        $transactions->today_count = Transaction::whereDate('transaction_date', today())->count();
        $transactions->today_amount = Transaction::whereDate('transaction_date', today())->sum('transaction_amount');
        $transactions->this_week_amount = Transaction::whereBetween('transaction_date', [now()->startOfWeek(), now()])->sum('transaction_amount');
        $transactions->this_month_amount = Transaction::whereMonth('transaction_date', now()->month)->sum('transaction_amount');

        $points = [
            'total_points' => Customer::sum('total_points'),
            'available_points' => Customer::sum('total_points') - Customer::sum('total_spent'),
            'used_points' => Customer::sum('total_spent'),
        ];
        $points['total_value_iqd'] = $points['available_points'] * config('points.iqd_per_point', 4);
        $points['pending_value'] = Transaction::where('transaction_status', 'pending')->sum('transaction_amount');

        $financial = [
            'total_system_value' => $points['total_value_iqd'],
            'today_transactions_value' => $transactions->today_amount,
            'week_transactions_value' => $transactions->this_week_amount,
            'month_transactions_value' => $transactions->this_month_amount,
            'average_transaction_value' => Transaction::avg('transaction_amount') ?? 0,
        ];

        return response()->json([
            'data' => compact('users', 'customers', 'transactions', 'points', 'financial')
        ]);
    }

    public function analytics()
    {
        $recentActivity = Transaction::latest()->take(10)->get()->map(function ($t) {
            return [
                'id' => $t->id,
                'description' => "Transaction #{$t->transaction_number} - {$t->transaction_type}",
                'type' => $t->transaction_status === 'approved' ? 'success' : ($t->transaction_status === 'pending' ? 'warning' : 'error'),
                'time' => $t->transaction_date->diffForHumans(),
            ];
        });

        $systemAlerts = [
            [
                'id' => 1,
                'title' => 'مزامنة النظام',
                'message' => 'تمت آخر مزامنة بيانات قبل 5 دقائق.',
            ],
            [
                'id' => 2,
                'title' => 'تذكير بنسخ احتياطي',
                'message' => 'قم بأخذ نسخة احتياطية اليوم.',
            ]
        ];

        $performance = [
            'response_time' => rand(180, 600),
            'uptime' => 97.3,
            'active_sessions' => rand(5, 20),
            'cache_hit_rate' => 88.5
        ];

        return response()->json([
            'recent_activity' => $recentActivity,
            'alerts' => $systemAlerts,
            'performance' => $performance,
        ]);
    }
}
