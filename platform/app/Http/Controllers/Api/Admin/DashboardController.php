<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\CommissionEntry;
use App\Models\Order;
use App\Models\TradespersonProfile;
use App\Models\Wallet;
use App\Models\WalletTopup;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /** Aggregate KPIs — cached briefly to stay cheap at 50k+ orders/day. */
    public function metrics(): JsonResponse
    {
        $data = cache()->remember('admin:metrics', 20, function () {
            $today = now()->startOfDay();

            return [
                'orders_today' => Order::where('created_at', '>=', $today)->count(),
                'orders_open' => Order::open()->count(),
                'orders_pending_dispatch' => Order::where('status', OrderStatus::PendingDispatch->value)->count(),
                'gmv_today' => (int) Order::where('created_at', '>=', $today)->sum('total_estimate'),
                'gmv_month' => (int) Order::where('created_at', '>=', now()->startOfMonth())->sum('total_estimate'),
                'commission_accrued_month' => (int) CommissionEntry::where('created_at', '>=', now()->startOfMonth())->sum('commission_amount'),
                'commission_settled_month' => (int) CommissionEntry::where('status', 'settled')
                    ->where('settled_at', '>=', now()->startOfMonth())->sum('commission_amount'),
                'kyc_pending' => TradespersonProfile::whereIn('kyc_status', ['pending', 'in_review'])->count(),
                'topups_pending' => WalletTopup::where('status', 'pending')->count(),
                'tradespeople_verified' => TradespersonProfile::where('kyc_status', 'verified')->count(),
                'platform_float' => (int) Wallet::where('type', 'platform_escrow')->sum('available_balance'),
                'commission_receivable' => (int) Wallet::where('type', 'tradesperson')->sum('commission_due'),
                'by_status' => Order::select('status', DB::raw('count(*) c'))->groupBy('status')->pluck('c', 'status'),
                'by_governorate' => Order::where('created_at', '>=', now()->subDays(7))
                    ->select('governorate_id', DB::raw('count(*) c'))->groupBy('governorate_id')
                    ->orderByDesc('c')->limit(10)->pluck('c', 'governorate_id'),
                'currency' => config('platform.currency'),
                'generated_at' => now()->toIso8601String(),
            ];
        });

        return response()->json(['data' => $data]);
    }

    /** Real-time feed for the dispatch panel (poll every ~10s or wire to SSE later). */
    public function live(): JsonResponse
    {
        $orders = Order::query()
            ->whereIn('status', [
                OrderStatus::PendingDispatch->value, OrderStatus::Dispatched->value,
                OrderStatus::Accepted->value, OrderStatus::EnRoute->value, OrderStatus::InProgress->value,
            ])
            ->with(['service:id,name_ar,name_en', 'governorate:id,name_ar,name_en,code', 'tradesperson:id,display_name', 'latestDispatch'])
            ->latest('updated_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $orders]);
    }
}
