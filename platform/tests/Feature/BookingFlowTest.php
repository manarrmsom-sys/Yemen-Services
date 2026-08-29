<?php

namespace Tests\Feature;

use App\Models\Governorate;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingFlowTest extends TestCase
{
    use RefreshDatabase;

    private function seedCatalog(): array
    {
        $cat = ServiceCategory::create(['slug' => 'plumbing', 'name_ar' => 'سباكة', 'name_en' => 'Plumbing']);
        $service = Service::create([
            'service_category_id' => $cat->id, 'slug' => 'leak', 'name_ar' => 'تسريب', 'name_en' => 'Leak',
            'pricing_model' => 'fixed', 'base_price' => 8000, 'callout_fee' => 2000, 'min_duration_minutes' => 60,
        ]);
        $gov = Governorate::create(['code' => 'sanaa', 'name_ar' => 'صنعاء', 'name_en' => 'Sanaa', 'dispatch_multiplier' => 1.0]);

        return [$service, $gov];
    }

    public function test_guest_can_get_an_estimate_without_auth(): void
    {
        [$service, $gov] = $this->seedCatalog();

        $this->postJson('/api/v1/estimate', [
            'service_id' => $service->id,
            'governorate_id' => $gov->id,
            'urgency' => 'today',
        ])->assertOk()->assertJsonPath('data.total', 10000);
    }

    public function test_guest_can_create_a_booking_and_receive_a_whatsapp_deeplink(): void
    {
        [$service, $gov] = $this->seedCatalog();
        \App\Models\Wallet::create(['owner_type' => null, 'owner_id' => null, 'type' => 'platform_escrow']);
        \App\Models\Wallet::create(['owner_type' => null, 'owner_id' => null, 'type' => 'platform_revenue']);

        $res = $this->postJson('/api/v1/bookings', [
            'service_id' => $service->id,
            'governorate_id' => $gov->id,
            'district' => 'الحصبة',
            'urgency' => 'urgent',
            'contact_name' => 'اختبار',
            'contact_phone' => '+967771234567',
            'payment_method' => 'cod',
        ]);

        $res->assertCreated()
            ->assertJsonStructure(['data' => ['order' => ['reference'], 'dispatch' => ['wa_deeplink']]]);

        $this->assertStringContainsString('wa.me', $res->json('data.dispatch.wa_deeplink'));
        $this->assertDatabaseHas('orders', ['status' => 'dispatched']);
        $this->assertDatabaseHas('dispatch_jobs', ['mode' => 'broadcast']);
    }
}
