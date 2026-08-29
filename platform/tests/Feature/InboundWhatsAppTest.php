<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\ProcessInboundWhatsAppEvent;
use App\Models\DispatchJob;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\TradespersonProfile;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InboundWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        Wallet::create(['owner_type' => null, 'owner_id' => null, 'type' => 'platform_escrow']);
        Wallet::create(['owner_type' => null, 'owner_id' => null, 'type' => 'platform_revenue']);

        $cat = ServiceCategory::create(['slug' => 'plumbing', 'name_ar' => 'سباكة', 'name_en' => 'Plumbing']);
        $service = Service::create([
            'service_category_id' => $cat->id, 'slug' => 'leak', 'name_ar' => 'تسريب', 'name_en' => 'Leak',
            'pricing_model' => 'fixed', 'base_price' => 8000, 'callout_fee' => 2000, 'min_duration_minutes' => 60,
        ]);
        $gov = Governorate::create(['code' => 'sanaa', 'name_ar' => 'صنعاء', 'name_en' => 'Sanaa', 'dispatch_multiplier' => 1.0]);

        $proUser = User::factory()->tradesperson()->create(['phone' => '+967711222333']);
        $pro = TradespersonProfile::create([
            'user_id' => $proUser->id, 'primary_category_id' => $cat->id, 'base_governorate_id' => $gov->id,
            'display_name' => 'فني', 'kyc_status' => 'verified', 'is_accepting_jobs' => true,
        ]);

        $order = Order::create([
            'service_id' => $service->id, 'service_category_id' => $cat->id, 'governorate_id' => $gov->id,
            'status' => OrderStatus::Dispatched, 'urgency' => 'today', 'subtotal' => 8000, 'total_estimate' => 12000,
            'commission_percent' => 12, 'commission_amount' => 960, 'contact_name' => 'عميل', 'contact_phone' => '+967733111222',
            'price_breakdown' => ['lines' => [['label_ar' => 'تسريب', 'label_en' => 'Leak', 'amount' => 8000]]],
        ]);
        $job = DispatchJob::create([
            'order_id' => $order->id, 'mode' => 'broadcast', 'status' => 'sent',
            'expires_at' => now()->addMinutes(30), 'attempt' => 1,
        ]);

        return [$order, $job, $pro];
    }

    private function payload(string $from, string $body): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'contacts' => [['wa_id' => ltrim($from, '+'), 'profile' => ['name' => 'Test']]],
                        'messages' => [[
                            'from' => ltrim($from, '+'),
                            'id' => 'wamid.'.uniqid(),
                            'type' => 'text',
                            'text' => ['body' => $body],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    public function test_receiver_queues_a_processing_job(): void
    {
        Queue::fake();
        $this->postJson('/webhooks/whatsapp', $this->payload('+967711222333', 'نعم'))
            ->assertOk()->assertJson(['received' => true]);
        Queue::assertPushed(ProcessInboundWhatsAppEvent::class);
    }

    public function test_tradesperson_yes_assigns_and_accepts_the_order(): void
    {
        [$order, $job, $pro] = $this->scenario();

        $this->postJson('/webhooks/whatsapp', $this->payload('+967711222333', 'نعم موافق'))->assertOk();

        // Process the queued job synchronously.
        $event = \App\Models\InboundEvent::latest('id')->first();
        (new ProcessInboundWhatsAppEvent($event->id))->handle(
            app(\App\Services\Dispatch\DispatchService::class),
            app(\App\Services\Messaging\WhatsAppCloudChannel::class),
        );

        $this->assertSame($pro->id, $order->fresh()->tradesperson_profile_id);
        $this->assertSame(OrderStatus::Accepted->value, $order->fresh()->status->value);
        $this->assertSame('accepted', $job->fresh()->status->value);
        $this->assertSame('processed', $event->fresh()->status);
    }

    public function test_tradesperson_no_declines_without_assigning(): void
    {
        [$order, $job] = $this->scenario();

        $this->postJson('/webhooks/whatsapp', $this->payload('+967711222333', 'لا مشغول'))->assertOk();
        $event = \App\Models\InboundEvent::latest('id')->first();
        (new ProcessInboundWhatsAppEvent($event->id))->handle(
            app(\App\Services\Dispatch\DispatchService::class),
            app(\App\Services\Messaging\WhatsAppCloudChannel::class),
        );

        $this->assertNull($order->fresh()->tradesperson_profile_id);
        $this->assertSame('declined', $job->fresh()->status->value);
    }
}
