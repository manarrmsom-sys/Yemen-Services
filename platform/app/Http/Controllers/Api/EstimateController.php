<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\EstimateRequest;
use App\Models\Service;
use App\Services\Pricing\PriceEstimator;
use Illuminate\Http\JsonResponse;

class EstimateController extends Controller
{
    public function __construct(private readonly PriceEstimator $estimator) {}

    /** Public dynamic price estimator — no auth required. */
    public function store(EstimateRequest $request): JsonResponse
    {
        $service = Service::with('pricingRules')->findOrFail($request->integer('service_id'));

        $quote = $this->estimator->estimate($service, $request->validated());
        $commission = $this->estimator->commissionFor($quote->subtotal);

        return response()->json([
            'data' => $quote->toArray() + [
                'commission_preview' => $commission,
                'service' => [
                    'id' => $service->id,
                    'name' => app()->getLocale() === 'ar' ? $service->name_ar : $service->name_en,
                    'pricing_model' => $service->pricing_model->value,
                ],
            ],
        ]);
    }
}
