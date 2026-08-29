<?php

namespace Tests\Unit;

use App\Enums\PricingModel;
use App\Models\Governorate;
use App\Models\Service;
use App\Services\Pricing\PriceEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceEstimatorTest extends TestCase
{
    use RefreshDatabase;

    private function service(array $attrs = []): Service
    {
        $cat = \App\Models\ServiceCategory::create([
            'slug' => 'plumbing-'.uniqid(), 'name_ar' => 'سباكة', 'name_en' => 'Plumbing',
        ]);

        return Service::create(array_merge([
            'service_category_id' => $cat->id,
            'slug' => 'svc-'.uniqid(),
            'name_ar' => 'خدمة', 'name_en' => 'Service',
            'pricing_model' => PricingModel::Fixed,
            'base_price' => 10000, 'callout_fee' => 2000,
            'hourly_rate' => 0, 'unit_price' => 0, 'min_duration_minutes' => 60,
        ], $attrs));
    }

    public function test_fixed_price_includes_callout_and_minimum(): void
    {
        $gov = Governorate::create(['code' => 'sanaa', 'name_ar' => 'صنعاء', 'name_en' => 'Sanaa', 'dispatch_multiplier' => 1.0]);
        $quote = app(PriceEstimator::class)->estimate($this->service(), [
            'governorate_id' => $gov->id, 'urgency' => 'today',
        ]);

        $this->assertSame(10000, $quote->subtotal);
        $this->assertSame(2000, $quote->calloutFee);
        $this->assertSame(12000, $quote->total);
    }

    public function test_urgent_surcharge_and_governorate_multiplier_apply(): void
    {
        config()->set('platform.pricing.urgent_surcharge_percent', 25);
        $gov = Governorate::create(['code' => 'marib', 'name_ar' => 'مأرب', 'name_en' => 'Marib', 'dispatch_multiplier' => 1.2]);

        $quote = app(PriceEstimator::class)->estimate($this->service(), [
            'governorate_id' => $gov->id, 'urgency' => 'urgent',
        ]);

        // subtotal 10000; gov adj = 10000*0.2 = 2000; callout 2000; urgent = (10000+2000)*0.25 = 3000
        // total = 10000 + 2000 + 2000 + 3000 = 17000
        $this->assertSame(2000, $quote->governorateAdjustment);
        $this->assertSame(3000, $quote->urgentSurcharge);
        $this->assertSame(17000, $quote->total);
    }

    public function test_quote_model_flags_requires_quote(): void
    {
        $gov = Governorate::create(['code' => 'aden', 'name_ar' => 'عدن', 'name_en' => 'Aden', 'dispatch_multiplier' => 1.0]);
        $quote = app(PriceEstimator::class)->estimate(
            $this->service(['pricing_model' => PricingModel::Quote, 'callout_fee' => 3000]),
            ['governorate_id' => $gov->id],
        );

        $this->assertTrue($quote->requiresQuote);
        $this->assertSame(3000, $quote->total);
    }
}
