<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\B2bRequest;
use App\Models\Address;
use App\Models\DispatchJob;
use App\Models\District;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\TradespersonProfile;
use App\Models\User;
use App\Models\WalletTopup;
use App\Models\WebhookEndpoint;
use App\Services\Wallet\WalletService;
use Database\Seeders\DistrictSeeder;
use Database\Seeders\GovernorateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationsAndB2bTest extends TestCase
{
    use RefreshDatabase;

    private Governorate $city;

    private District $district;

    private ServiceCategory $category;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->city = Governorate::create(['code' => 'capital', 'name_ar' => 'الأمانة', 'name_en' => 'Capital']);
        $this->district = District::create(['governorate_id' => $this->city->id, 'name_ar' => 'السبعين', 'status' => 'active']);
        $this->category = ServiceCategory::create(['slug' => 'plumbing', 'name_ar' => 'سباكة', 'name_en' => 'Plumbing']);
        $this->service = Service::create([
            'service_category_id' => $this->category->id, 'slug' => 'leak', 'name_ar' => 'تسريب', 'name_en' => 'Leak',
            'pricing_model' => 'fixed', 'base_price' => 8000, 'callout_fee' => 2000,
        ]);
        config(['platform.dispatch.sla_minutes' => 10, 'platform.wallet.min_float' => 5000]);
    }

    private function order(array $attrs = [], ?District $district = null): Order
    {
        $address = Address::create(['governorate_id' => $this->city->id, 'district_id' => ($district ?? $this->district)->id, 'district' => 'السبعين']);

        return Order::create($attrs + [
            'service_id' => $this->service->id, 'service_category_id' => $this->category->id, 'governorate_id' => $this->city->id,
            'address_id' => $address->id, 'status' => OrderStatus::Dispatched, 'urgency' => 'today',
            'subtotal' => 8000, 'total_estimate' => 10000, 'commission_percent' => 12, 'commission_amount' => 960,
            'contact_name' => 'عميل', 'contact_phone' => '+967733111222', 'price_breakdown' => ['lines' => []],
            'dispatched_at' => now(),
        ]);
    }

    private function job(Order $order, array $attrs = []): DispatchJob
    {
        return DispatchJob::create($attrs + [
            'order_id' => $order->id, 'mode' => 'broadcast', 'status' => 'sent', 'wa_deeplink' => 'https://wa.me/967774274848?text=x',
            'expires_at' => now()->addMinutes(30), 'attempt' => 1,
        ]);
    }

    /** A verified, accepting, funded pro placed in a district. */
    private function readyPro(array $profile = [], int $float = 6000): TradespersonProfile
    {
        $pro = TradespersonProfile::create($profile + [
            'user_id' => User::factory()->tradesperson()->create()->id,
            'primary_category_id' => $this->category->id, 'base_governorate_id' => $this->city->id, 'base_district_id' => $this->district->id,
            'display_name' => 'فني جاهز', 'kyc_status' => 'verified', 'is_accepting_jobs' => true,
        ]);
        if ($float > 0) {
            $wallets = app(WalletService::class);
            $wallets->confirmTopup(WalletTopup::create([
                'wallet_id' => $wallets->walletFor($pro)->id, 'tradesperson_profile_id' => $pro->id,
                'amount' => $float, 'method' => 'bank_transfer', 'status' => 'confirmed',
            ]));
        }

        return $pro;
    }

    private function staff(): void
    {
        Sanctum::actingAs(User::factory()->dispatcher()->create());
    }

    // =====================================================================
    // 1. Hyper-local launch
    // =====================================================================

    public function test_seeding_leaves_only_the_capital_live_with_its_four_districts(): void
    {
        Governorate::query()->delete();
        $this->seed([GovernorateSeeder::class, DistrictSeeder::class]);

        $live = Governorate::where('is_active', true)->pluck('code')->all();
        $this->assertSame(['amanat_al_asimah'], $live, 'Only a governorate with an active district is live.');

        $this->assertEqualsCanonicalizing(
            ['السبعين', 'معين', 'الوحدة', 'التحرير'],
            District::where('status', 'active')->pluck('name_ar')->all(),
        );
        $this->assertFalse(Governorate::where('code', 'aden')->value('is_active') == true, 'Aden has districts, none active.');
        $this->assertFalse(Governorate::where('code', 'taiz')->value('is_active') == true, 'No districts at all: not live.');
    }

    public function test_reseeding_keeps_governorates_an_admin_switched_on_or_off(): void
    {
        $this->seed([GovernorateSeeder::class, DistrictSeeder::class]);
        Governorate::where('code', 'aden')->update(['is_active' => true]);
        Governorate::where('code', 'amanat_al_asimah')->update(['is_active' => false]);

        $this->seed([GovernorateSeeder::class, DistrictSeeder::class]);

        $this->assertTrue((bool) Governorate::where('code', 'aden')->value('is_active'));
        $this->assertFalse((bool) Governorate::where('code', 'amanat_al_asimah')->value('is_active'));
    }

    public function test_a_freshly_seeded_platform_only_accepts_bookings_in_the_live_districts(): void
    {
        Governorate::query()->delete();
        $this->seed([GovernorateSeeder::class, DistrictSeeder::class]);
        $capital = Governorate::where('code', 'amanat_al_asimah')->first();
        $taiz = Governorate::where('code', 'taiz')->first();
        $base = ['service_id' => $this->service->id, 'urgency' => 'today', 'contact_name' => 'منار', 'contact_phone' => '+967771234567', 'payment_method' => 'cod'];

        $this->postJson('/api/v1/bookings', $base + ['governorate_id' => $capital->id, 'district_id' => $capital->districts()->where('name_ar', 'معين')->value('id')])->assertCreated();
        $this->postJson('/api/v1/bookings', $base + ['governorate_id' => $capital->id, 'district_id' => $capital->districts()->where('name_ar', 'شعوب')->value('id')])->assertStatus(422);
        $this->postJson('/api/v1/bookings', $base + ['governorate_id' => $taiz->id, 'district' => 'القاهرة'])
            ->assertStatus(422)->assertJsonValidationErrors(['governorate_id']);

        // The booking form only offers the live governorate.
        $this->get('/')->assertInertia(fn ($page) => $page->has('governorates', 1)->where('governorates.0.code', 'amanat_al_asimah'));
    }

    // =====================================================================
    // 2. Operations room
    // =====================================================================

    public function test_board_times_each_unaccepted_order_and_flags_those_past_the_sla(): void
    {
        $fresh = $this->job($this->order(['dispatched_at' => now()->subMinutes(4)]));
        $late = $this->job($this->order(['dispatched_at' => now()->subMinutes(17)]));
        $edge = $this->job($this->order(['dispatched_at' => now()->subMinutes(10)]));
        $accepted = $this->job($this->order(['dispatched_at' => now()->subMinutes(40), 'accepted_at' => now()->subMinutes(35), 'status' => OrderStatus::Accepted]), ['status' => 'accepted']);

        $this->staff();
        $res = $this->getJson('/api/v1/admin/dispatch/board')->assertOk()->assertJsonPath('sla_minutes', 10);
        $sent = collect($res->json('data.sent'))->keyBy('id');

        $this->assertSame([4, false], [$sent[$fresh->id]['waiting_minutes'], $sent[$fresh->id]['overdue']]);
        $this->assertSame([17, true], [$sent[$late->id]['waiting_minutes'], $sent[$late->id]['overdue']]);
        $this->assertTrue($sent[$edge->id]['overdue'], 'Exactly 10 minutes counts as past the SLA.');
        $this->assertNotNull($sent[$late->id]['waiting_since']);
        $this->assertSame($late->id, $res->json('data.sent.0.id'), 'Longest wait is listed first.');

        // Accepted jobs are no longer timed.
        $done = $res->json('data.accepted.0');
        $this->assertSame([$accepted->id, false, null, false], [$done['id'], $done['waiting'], $done['waiting_minutes'], $done['overdue']]);

        // The board is an operations view: no customer contact details in it.
        $this->assertStringNotContainsString('733111222', $res->getContent());
    }

    public function test_sla_threshold_is_configurable(): void
    {
        config(['platform.dispatch.sla_minutes' => 3]);
        $this->job($this->order(['dispatched_at' => now()->subMinutes(4)]));

        $this->staff();
        $this->getJson('/api/v1/admin/dispatch/board')->assertOk()
            ->assertJsonPath('sla_minutes', 3)->assertJsonPath('data.sent.0.overdue', true);
    }

    public function test_kpis_report_acceptance_time_fulfilment_and_estimate_accuracy(): void
    {
        // Acceptance: 4 and 10 minutes -> 7.0 average.
        $this->order(['dispatched_at' => now()->subMinutes(30), 'accepted_at' => now()->subMinutes(26), 'status' => OrderStatus::Accepted]);
        $this->order(['dispatched_at' => now()->subMinutes(30), 'accepted_at' => now()->subMinutes(20), 'status' => OrderStatus::Accepted]);
        // Fulfilment: 3 completed/closed of 4 ended -> 75%.
        // Accuracy: finals 10000 (0%), 11000 (10%), 8000 (20%) off a 10000 estimate -> mean gap 10% -> 90%.
        $this->order(['status' => OrderStatus::Closed, 'total_final' => 10000]);
        $this->order(['status' => OrderStatus::Closed, 'total_final' => 11000]);
        $this->order(['status' => OrderStatus::Completed, 'total_final' => 8000]);
        $this->order(['status' => OrderStatus::Cancelled]);
        // Outside the 30-day window: ignored.
        $old = $this->order(['status' => OrderStatus::Cancelled]);
        Order::whereKey($old->id)->update(['created_at' => now()->subDays(45)]);

        $this->staff();
        $kpis = $this->getJson('/api/v1/admin/dispatch/board')->assertOk()->json('kpis');

        $this->assertEquals(7.0, $kpis['avg_acceptance_minutes']);
        $this->assertSame(2, $kpis['accepted_orders']);
        $this->assertEquals(75.0, $kpis['fulfilment_rate']);
        $this->assertSame(4, $kpis['ended_orders']);
        $this->assertEquals(90.0, $kpis['estimate_accuracy']);
        $this->assertSame(3, $kpis['priced_orders']);
        $this->assertSame(30, $kpis['window_days']);
    }

    public function test_kpis_are_null_rather_than_zero_when_there_is_no_data(): void
    {
        $this->staff();
        $kpis = $this->getJson('/api/v1/admin/dispatch/board')->assertOk()->json('kpis');

        $this->assertNull($kpis['avg_acceptance_minutes']);
        $this->assertNull($kpis['fulfilment_rate']);
        $this->assertNull($kpis['estimate_accuracy']);
    }

    public function test_alternates_are_ready_pros_in_the_same_district_and_trade_with_whatsapp_links(): void
    {
        $current = $this->readyPro(['display_name' => 'الحالي']);
        $good = $this->readyPro(['display_name' => 'البديل', 'rating_avg' => 4.9]);
        $other = District::create(['governorate_id' => $this->city->id, 'name_ar' => 'معين', 'status' => 'active']);
        $design = ServiceCategory::create(['slug' => 'design', 'name_ar' => 'تصميم', 'name_en' => 'Design']);

        $this->readyPro(['display_name' => 'حي آخر', 'base_district_id' => $other->id]);
        $this->readyPro(['display_name' => 'تخصص آخر', 'primary_category_id' => $design->id]);
        $this->readyPro(['display_name' => 'غير موثق', 'kyc_status' => 'pending']);
        $this->readyPro(['display_name' => 'متوقف', 'is_accepting_jobs' => false]);
        $this->readyPro(['display_name' => 'بلا رصيد'], float: 0);

        $job = $this->job($this->order(), ['tradesperson_profile_id' => $current->id, 'mode' => 'targeted']);

        $this->staff();
        $list = $this->getJson("/api/v1/admin/dispatch/{$job->id}/alternates")->assertOk()->json('data');

        $this->assertSame([$good->id], array_column($list, 'id'), 'Only the ready pro in the same district and trade, not the current one.');
        $phone = ltrim($good->user->phone, '+');
        $this->assertStringStartsWith("https://wa.me/{$phone}?text=", $list[0]['wa_deeplink']);
        $this->assertStringContainsString(rawurlencode($job->order->reference), $list[0]['wa_deeplink']);
    }

    public function test_alternates_are_empty_when_the_order_has_no_district(): void
    {
        $this->readyPro();
        $order = $this->order();
        $order->update(['address_id' => null]);

        $this->staff();
        $this->getJson('/api/v1/admin/dispatch/'.$this->job($order)->id.'/alternates')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_nudge_is_recorded_on_the_order_timeline(): void
    {
        $alt = $this->readyPro(['display_name' => 'البديل']);
        $job = $this->job($this->order());
        $this->staff();

        $this->postJson("/api/v1/admin/dispatch/{$job->id}/nudge")->assertCreated();
        $this->postJson("/api/v1/admin/dispatch/{$job->id}/nudge", ['tradesperson_profile_id' => $alt->id])->assertCreated();

        $events = \App\Models\OrderEvent::where('order_id', $job->order_id)->where('type', 'dispatch_nudge')->orderBy('id')->pluck('message');
        $this->assertCount(2, $events);
        $this->assertStringContainsString('reminder', $events[0]);
        $this->assertStringContainsString('البديل', $events[1]);
        $this->assertSame(3, $job->fresh()->attempt);

        $this->postJson("/api/v1/admin/dispatch/{$job->id}/nudge", ['tradesperson_profile_id' => 999])->assertStatus(422);
    }

    public function test_operations_endpoints_are_staff_only(): void
    {
        $job = $this->job($this->order());

        foreach ([['GET', '/api/v1/admin/dispatch/board'], ['GET', "/api/v1/admin/dispatch/{$job->id}/alternates"], ['POST', "/api/v1/admin/dispatch/{$job->id}/nudge"]] as [$method, $url]) {
            $this->json($method, $url)->assertUnauthorized();
        }
        Sanctum::actingAs(User::factory()->create());
        foreach ([['GET', '/api/v1/admin/dispatch/board'], ['GET', "/api/v1/admin/dispatch/{$job->id}/alternates"], ['POST', "/api/v1/admin/dispatch/{$job->id}/nudge"]] as [$method, $url]) {
            $this->json($method, $url)->assertForbidden();
        }
    }

    // =====================================================================
    // 3. B2B maintenance contracts
    // =====================================================================

    private function lead(array $overrides = []): array
    {
        return $overrides + [
            'business_name' => 'فندق سبأ', 'business_type' => 'hotel', 'governorate_id' => $this->city->id,
            'district_id' => $this->district->id, 'facility_size' => '40 غرفة، 25 مكيفاً', 'phone' => '0771 234 567',
        ];
    }

    public function test_a_business_can_request_a_quote(): void
    {
        $res = $this->postJson('/api/v1/b2b-requests', $this->lead(['contact_name' => 'أحمد', 'notes' => 'نحتاج زيارة شهرية']))
            ->assertCreated()->assertJsonStructure(['data' => ['reference']]);

        $lead = B2bRequest::sole();
        $this->assertSame($res->json('data.reference'), $lead->reference);
        $this->assertMatchesRegularExpression('/^B2B-[A-Z0-9]{6}$/', $lead->reference);
        $this->assertSame(['فندق سبأ', 'hotel', 'new', '+771234567'], [$lead->business_name, $lead->business_type, $lead->status, $lead->phone]);
        $this->assertSame($this->district->id, $lead->district_id);

        // The response gives away nothing but the reference.
        $this->assertSame(['reference'], array_keys($res->json('data')));
    }

    public function test_quote_request_validates_input_and_strips_markup(): void
    {
        $from = fn (int $n) => $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.$n"]);

        $from(1)->postJson('/api/v1/b2b-requests', [])->assertStatus(422)
            ->assertJsonValidationErrors(['business_name', 'business_type', 'facility_size', 'phone', 'district_name']);
        $from(2)->postJson('/api/v1/b2b-requests', $this->lead(['business_type' => 'casino']))->assertStatus(422)->assertJsonValidationErrors(['business_type']);
        $from(3)->postJson('/api/v1/b2b-requests', $this->lead(['phone' => 'call me']))->assertStatus(422)->assertJsonValidationErrors(['phone']);

        $elsewhere = District::create(['governorate_id' => Governorate::create(['code' => 'aden', 'name_ar' => 'عدن', 'name_en' => 'Aden'])->id, 'name_ar' => 'كريتر']);
        $from(4)->postJson('/api/v1/b2b-requests', $this->lead(['district_id' => $elsewhere->id]))->assertStatus(422)->assertJsonValidationErrors(['district_id']);
        $this->assertSame(0, B2bRequest::count());

        // Unlisted district by name, with markup removed everywhere.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->postJson('/api/v1/b2b-requests', $this->lead([
            'business_name' => '<script>alert(1)</script>مطعم الشام', 'district_id' => null, 'district_name' => '<b>حدة</b>',
            'facility_size' => '<i>3 فروع</i>', 'phone' => '+967770000111', 'status' => 'won', 'admin_note' => 'injected',
        ]))->assertCreated();

        $lead = B2bRequest::sole();
        $this->assertSame(['alert(1)مطعم الشام', 'حدة', '3 فروع'], [$lead->business_name, $lead->district_name, $lead->facility_size]);
        $this->assertSame(['new', null], [$lead->status, $lead->admin_note], 'Pipeline fields cannot be set by the public form.');
    }

    public function test_quote_requests_are_rate_limited_per_ip_and_per_phone(): void
    {
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/b2b-requests', $this->lead(['phone' => "+96777000000$i"]))->assertCreated();
        }
        $this->postJson('/api/v1/b2b-requests', $this->lead(['phone' => '+967770000009']))->assertStatus(429);

        foreach (range(1, 5) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.$i"])
                ->postJson('/api/v1/b2b-requests', $this->lead(['phone' => '+967 771 111 111']))->assertCreated();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->postJson('/api/v1/b2b-requests', $this->lead(['phone' => '+967771111111']))->assertStatus(429);
    }

    public function test_a_new_request_notifies_n8n_with_a_valid_signature(): void
    {
        config(['platform.n8n.b2b_url' => 'https://n8n.example.test/webhook/b2b', 'platform.n8n.secret' => 'shared-secret']);
        Http::fake(['n8n.example.test/*' => Http::response(['ok' => true])]);

        $reference = $this->postJson('/api/v1/b2b-requests', $this->lead())->assertCreated()->json('data.reference');

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($reference) {
            $body = $request->body();
            $json = json_decode($body, true);

            return $request->url() === 'https://n8n.example.test/webhook/b2b'
                && hash_equals(hash_hmac('sha256', $body, 'shared-secret'), $request->header('X-Signature')[0])
                && $json['event'] === 'b2b.request_created'
                && $json['data']['reference'] === $reference
                && $json['data']['business_name'] === 'فندق سبأ'
                && $json['data']['district'] === 'السبعين'
                && $json['data']['whatsapp_link'] === 'https://wa.me/771234567';
        });
    }

    public function test_a_failing_or_missing_n8n_never_breaks_the_request(): void
    {
        // Not configured: nothing is sent.
        config(['platform.n8n.b2b_url' => null]);
        Http::fake();
        $this->postJson('/api/v1/b2b-requests', $this->lead())->assertCreated();
        Http::assertNothingSent();

        // Configured but down: the visitor still gets a reference.
        config(['platform.n8n.b2b_url' => 'https://n8n.example.test/webhook/b2b']);
        Http::fake(['n8n.example.test/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('down')]);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.30'])
            ->postJson('/api/v1/b2b-requests', $this->lead(['phone' => '+967770000222']))->assertCreated();
        $this->assertSame(2, B2bRequest::count());
    }

    public function test_registered_webhook_endpoints_receive_the_signed_event(): void
    {
        $endpoint = WebhookEndpoint::create([
            'name' => 'n8n', 'url' => 'https://hooks.example.test/in', 'secret' => 'endpoint-secret',
            'events' => ['b2b.request_created'], 'is_active' => true,
        ]);
        WebhookEndpoint::create(['name' => 'orders only', 'url' => 'https://hooks.example.test/orders', 'secret' => 's', 'events' => ['order.created'], 'is_active' => true]);
        Http::fake(['hooks.example.test/*' => Http::response('ok')]);

        $this->postJson('/api/v1/b2b-requests', $this->lead())->assertCreated();

        $this->assertDatabaseHas('webhook_deliveries', ['webhook_endpoint_id' => $endpoint->id, 'event_type' => 'b2b.request_created']);
        $this->assertSame(1, \App\Models\WebhookDelivery::count(), 'Only subscribed endpoints get it.');
        Http::assertSent(fn ($request) => $request->url() === 'https://hooks.example.test/in'
            && str_contains($request->body(), 'b2b.request_created'));
    }

    public function test_staff_manage_the_b2b_pipeline(): void
    {
        $this->postJson('/api/v1/b2b-requests', $this->lead())->assertCreated();
        $lead = B2bRequest::sole();
        $staff = User::factory()->dispatcher()->create();
        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/admin/b2b-requests?status=new')->assertOk()
            ->assertJsonPath('data.0.reference', $lead->reference)
            ->assertJsonPath('data.0.phone', '+771234567')
            ->assertJsonPath('data.0.district.name_ar', 'السبعين')
            ->assertJsonPath('counts.new', 1);

        $this->patchJson("/api/v1/admin/b2b-requests/{$lead->id}", ['status' => 'quoted', 'admin_note' => 'أُرسل العرض'])->assertOk()
            ->assertJsonPath('data.status', 'quoted')->assertJsonPath('data.handler.name', $staff->name);
        $this->assertNotNull($lead->fresh()->handled_at);

        $this->getJson('/api/v1/admin/b2b-requests?status=new')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('counts.quoted', 1);
        $this->patchJson("/api/v1/admin/b2b-requests/{$lead->id}", ['status' => 'archived'])->assertStatus(422);
    }

    public function test_b2b_leads_are_private_to_staff(): void
    {
        $this->postJson('/api/v1/b2b-requests', $this->lead())->assertCreated();
        $lead = B2bRequest::sole();

        $this->getJson('/api/v1/admin/b2b-requests')->assertUnauthorized();
        $this->get('/admin/b2b')->assertRedirect('/login');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/b2b-requests')->assertForbidden();
        $this->patchJson("/api/v1/admin/b2b-requests/{$lead->id}", ['status' => 'won'])->assertForbidden();
        $this->assertSame('new', $lead->fresh()->status);
    }
}
