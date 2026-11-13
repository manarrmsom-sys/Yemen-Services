<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Order::query()
            ->with(['service', 'category', 'governorate', 'latestDispatch',
                'tradesperson:id,display_name,image_url,badges,rating_avg,rating_count'])
            ->latest();

        if ($user->role->value === 'tradesperson' && $user->tradespersonProfile) {
            $query->where('tradesperson_profile_id', $user->tradespersonProfile->id);
        } else {
            $query->where('customer_id', $user->id);
        }

        return response()->json($query->paginate(15));
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        // No actor / internal notes on events, and only the assigned pro's phone
        // (the customer needs it to coordinate) — never their email.
        $order->load(['service', 'category', 'governorate', 'address', 'items', 'review', 'latestDispatch',
            'tradesperson:id,user_id,display_name,image_url,badges,rating_avg,rating_count',
            'tradesperson.user:id,phone',
            'events' => fn ($q) => $q->select(['id', 'order_id', 'type', 'from_status', 'to_status', 'created_at'])]);
        $order->tradesperson?->user?->makeVisible(['phone']);

        return response()->json(['data' => $order]);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->authorize('cancel', $order);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        $this->orders->transition($order, OrderStatus::Cancelled, $request->user(), $data['reason'] ?? 'Cancelled by customer');

        return response()->json(['data' => $this->orders->summary($order->fresh())]);
    }

    public function review(Request $request, Order $order): JsonResponse
    {
        $this->authorize('review', $order);
        abort_if($order->review()->exists(), 409, __('errors.already_reviewed'));

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $review = $order->review()->create([
            'customer_id' => $request->user()->id,
            'tradesperson_profile_id' => $order->tradesperson_profile_id,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
        ]);

        // Recompute the tradesperson's aggregate rating.
        $profile = $order->tradesperson;
        if ($profile) {
            $agg = $profile->reviews()->where('is_published', true)->selectRaw('AVG(rating) a, COUNT(*) c')->first();
            $profile->update(['rating_avg' => round($agg->a ?? 0, 2), 'rating_count' => $agg->c ?? 0]);
            $profile->recalculateBadges();
            $profile->save();
        }

        return response()->json(['data' => $review], 201);
    }
}
