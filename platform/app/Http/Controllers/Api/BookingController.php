<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreBookingRequest;
use App\Models\Address;
use App\Models\Order;
use App\Services\Dispatch\DispatchService;
use App\Services\Orders\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly DispatchService $dispatch,
    ) {}

    /**
     * Create an order from the 3-step booking drawer.
     * Works for guests (contact_phone only) and authenticated users alike.
     */
    public function store(StoreBookingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        // Materialise an ad-hoc address when the guest supplied a district/pin.
        if (empty($data['address_id']) && ! empty($data['district'])) {
            $address = Address::create([
                'user_id' => $user?->id,
                'governorate_id' => $data['governorate_id'],
                'district' => $data['district'],
                'directions' => $data['directions'] ?? null,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
                'contact_name' => $data['contact_name'],
                'contact_phone' => $data['contact_phone'],
            ]);
            $data['address_id'] = $address->id;
        }

        $order = $this->orders->createFromBooking($data, $user);

        // Dispatch is a follow-up integration; a provider timeout must not erase a valid order.
        $job = null;
        $dispatchError = null;
        try {
            $job = $this->dispatch->dispatchOrder($order, mode: 'broadcast');
        } catch (\Throwable $e) {
            $dispatchError = __('errors.dispatch_unavailable');
            Log::error('[booking] dispatch failed after order creation', [
                'order_id' => $order->id,
                'reference' => $order->reference,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'data' => [
                'order' => $this->orders->summary($order->fresh()),
                'dispatch' => [
                    'wa_deeplink' => $job?->wa_deeplink,
                    'status' => $job?->status->value ?? 'unavailable',
                    'error' => $dispatchError,
                ],
                'price_breakdown' => $order->price_breakdown,
            ],
        ], 201);
    }

    /** A guest who later signs in can claim their order by reference + phone match. */
    public function claim(Request $request, Order $order): JsonResponse
    {
        abort_if($order->customer_id, 409, __('errors.order_already_claimed'));
        abort_unless(
            $request->user()->phone === $order->contact_phone,
            403,
            __('errors.phone_mismatch'),
        );

        $order->update(['customer_id' => $request->user()->id]);

        return response()->json(['data' => $this->orders->summary($order)]);
    }

    /** Re-fetch the pre-filled WhatsApp deep link (e.g. after a network drop). */
    public function dispatchLink(Order $order): JsonResponse
    {
        return response()->json([
            'data' => ['wa_deeplink' => $this->dispatch->buildWaDeeplink($order)],
        ]);
    }
}
