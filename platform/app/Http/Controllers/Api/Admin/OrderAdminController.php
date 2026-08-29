<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\TradespersonProfile;
use App\Services\Orders\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderAdminController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with(['service', 'category', 'governorate', 'customer:id,name,phone', 'tradesperson:id,display_name'])
            ->when($request->string('status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->integer('governorate_id'), fn ($q, $id) => $q->where('governorate_id', $id))
            ->when($request->integer('category_id'), fn ($q, $id) => $q->where('service_category_id', $id))
            ->when($request->string('q')->isNotEmpty(), function ($q) use ($request) {
                $term = $request->string('q');
                $q->where(fn ($w) => $w->where('reference', 'like', "%{$term}%")
                    ->orWhere('contact_phone', 'like', "%{$term}%")
                    ->orWhere('contact_name', 'like', "%{$term}%"));
            })
            ->when($request->string('sort')->value() === 'oldest', fn ($q) => $q->oldest(), fn ($q) => $q->latest())
            ->paginate($request->integer('per_page', 25));

        return response()->json($orders);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load([
            'service', 'category', 'governorate', 'address', 'customer', 'tradesperson.user',
            'items', 'events.actor', 'dispatchJobs', 'commissionEntry', 'review',
        ]);

        return response()->json(['data' => $order]);
    }

    public function assign(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'tradesperson_profile_id' => ['required', 'integer', 'exists:tradesperson_profiles,id'],
        ]);

        $profile = TradespersonProfile::findOrFail($data['tradesperson_profile_id']);
        abort_unless($profile->isVerified(), 422, __('errors.assign_unverified'));

        $order->update(['tradesperson_profile_id' => $profile->id]);
        $this->orders->logEvent($order, 'assignment', null, null, "Assigned to {$profile->display_name}", $request->user());

        if ($order->status === OrderStatus::PendingDispatch || $order->status === OrderStatus::Dispatched) {
            $this->orders->transition($order, OrderStatus::Accepted, $request->user(), 'Manually assigned & accepted by ops');
        }

        return response()->json(['data' => $this->orders->summary($order->fresh())]);
    }

    public function transition(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'note' => ['nullable', 'string', 'max:400'],
        ]);

        $this->orders->transition($order, OrderStatus::from($data['status']), $request->user(), $data['note'] ?? null);

        return response()->json(['data' => $this->orders->summary($order->fresh())]);
    }

    public function note(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:1000']]);

        $event = $this->orders->logEvent($order, 'note', null, null, $data['message'], $request->user());

        return response()->json(['data' => $event], 201);
    }
}
