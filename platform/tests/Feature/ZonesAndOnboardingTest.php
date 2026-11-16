<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Governorate;
use App\Models\KycDocument;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\TradespersonProfile;
use App\Models\User;
use App\Models\WalletTopup;
use App\Models\ZoneWaitlist;
use App\Services\Wallet\WalletService;
use Database\Seeders\DistrictSeeder;
use Database\Seeders\GovernorateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ZonesAndOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private Governorate $city;

    private District $live;

    private District $soon;

    private ServiceCategory $category;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->city = Governorate::create(['code' => 'capital', 'name_ar' => 'الأمانة', 'name_en' => 'Capital', 'dispatch_multiplier' => 1.0]);
        $this->live = District::create(['governorate_id' => $this->city->id, 'name_ar' => 'السبعين', 'name_en' => 'Sabain', 'status' => 'active']);
        $this->soon = District::create(['governorate_id' => $this->city->id, 'name_ar' => 'شعوب', 'name_en' => 'Shuub', 'status' => 'coming_soon']);
        $this->category = ServiceCategory::create(['slug' => 'plumbing', 'name_ar' => 'سباكة', 'name_en' => 'Plumbing']);
        $this->service = Service::create([
            'service_category_id' => $this->category->id, 'slug' => 'leak', 'name_ar' => 'تسريب', 'name_en' => 'Leak',
            'pricing_model' => 'fixed', 'base_price' => 8000, 'callout_fee' => 2000,
        ]);
    }

    private function booking(array $overrides = []): array
    {
        return $overrides + [
            'service_id' => $this->service->id, 'governorate_id' => $this->city->id, 'urgency' => 'today',
            'contact_name' => 'منار', 'contact_phone' => '+967771234567', 'payment_method' => 'cod',
        ];
    }

    private function pro(array $profile = [], array $user = []): TradespersonProfile
    {
        return TradespersonProfile::create($profile + [
            'user_id' => User::factory()->tradesperson()->create($user)->id,
            'primary_category_id' => $this->category->id, 'base_governorate_id' => $this->city->id,
            'display_name' => 'فني', 'kyc_status' => 'verified', 'is_accepting_jobs' => true,
        ]);
    }

    // ---- booking is limited to live zones ---------------------------------

    public function test_booking_in_a_live_district_succeeds_and_records_the_district(): void
    {
        $res = $this->postJson('/api/v1/bookings', $this->booking(['district_id' => $this->live->id]))->assertCreated();

        $order = Order::where('reference', $res->json('data.order.reference'))->first();
        $this->assertSame($this->live->id, $order->address->district_id);
        $this->assertSame('السبعين', $order->address->district);
    }

    public function test_booking_is_refused_without_a_district_in_a_zoned_city(): void
    {
        $this->postJson('/api/v1/bookings', $this->booking())->assertStatus(422)->assertJsonValidationErrors(['district_id']);
        // Free text cannot be used to slip past the zone list.
        $this->postJson('/api/v1/bookings', $this->booking(['district' => 'شعوب']))->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_booking_is_refused_in_a_coming_soon_district_or_city(): void
    {
        $this->postJson('/api/v1/bookings', $this->booking(['district_id' => $this->soon->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['district_id']);

        $paused = Governorate::create(['code' => 'aden', 'name_ar' => 'عدن', 'name_en' => 'Aden', 'is_active' => false]);
        $this->postJson('/api/v1/bookings', $this->booking(['governorate_id' => $paused->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['governorate_id']);

        // A district from another city does not validate for this one.
        $elsewhere = District::create(['governorate_id' => $paused->id, 'name_ar' => 'كريتر', 'status' => 'active']);
        $this->postJson('/api/v1/bookings', $this->booking(['district_id' => $elsewhere->id]))->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_a_city_without_districts_is_served_as_a_whole(): void
    {
        $open = Governorate::create(['code' => 'taiz', 'name_ar' => 'تعز', 'name_en' => 'Taiz']);

        $this->postJson('/api/v1/bookings', $this->booking(['governorate_id' => $open->id, 'district' => 'القاهرة']))->assertCreated();
    }

    public function test_remote_services_ignore_zones(): void
    {
        $design = ServiceCategory::create(['slug' => 'design', 'name_ar' => 'تصميم', 'name_en' => 'Design', 'is_remote' => true]);
        $logo = Service::create(['service_category_id' => $design->id, 'slug' => 'logo', 'name_ar' => 'شعار', 'name_en' => 'Logo', 'pricing_model' => 'fixed', 'base_price' => 25000]);

        $this->postJson('/api/v1/bookings', $this->booking(['service_id' => $logo->id]))->assertCreated();
    }

    public function test_the_booking_page_receives_districts_with_their_status(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->where('governorates.0.districts.0.name', fn ($name) => in_array($name, ['Sabain', 'Shuub', 'السبعين', 'شعوب'], true))
            ->has('governorates.0.districts', 2)
            ->has('governorates.0.districts.0.status'));
    }

    // ---- waitlist -----------------------------------------------------------

    public function test_a_visitor_can_join_the_waitlist_for_a_coming_soon_district(): void
    {
        $payload = ['phone' => '0771 234 567', 'governorate_id' => $this->city->id, 'district_id' => $this->soon->id, 'service_category_id' => $this->category->id];

        $this->postJson('/api/v1/waitlist', $payload)->assertCreated()->assertJsonPath('data.already', false);
        // Idempotent: the same number for the same zone is one entry.
        $this->postJson('/api/v1/waitlist', $payload)->assertOk()->assertJsonPath('data.already', true);

        $entry = ZoneWaitlist::sole();
        $this->assertSame('+771234567', $entry->phone);
        $this->assertSame($this->soon->id, $entry->district_id);
    }

    public function test_an_unlisted_district_can_be_requested_by_name_with_markup_stripped(): void
    {
        $this->postJson('/api/v1/waitlist', [
            'phone' => '+967771234567', 'governorate_id' => $this->city->id, 'district_name' => '<b>حي الأندلس</b>',
        ])->assertCreated();

        $this->assertSame('حي الأندلس', ZoneWaitlist::sole()->district_name);
    }

    public function test_waitlist_rejects_served_zones_and_bad_input(): void
    {
        $this->postJson('/api/v1/waitlist', ['phone' => '+967771234567', 'governorate_id' => $this->city->id, 'district_id' => $this->live->id])
            ->assertStatus(422)->assertJsonValidationErrors(['district_id']);
        $this->postJson('/api/v1/waitlist', ['phone' => 'not-a-phone', 'governorate_id' => $this->city->id, 'district_id' => $this->soon->id])
            ->assertStatus(422)->assertJsonValidationErrors(['phone']);
        $this->postJson('/api/v1/waitlist', ['phone' => '+967771234567', 'governorate_id' => 999])
            ->assertStatus(422)->assertJsonValidationErrors(['governorate_id']);

        $this->assertSame(0, ZoneWaitlist::count());
    }

    public function test_waitlist_is_rate_limited_per_ip_and_per_phone(): void
    {
        // Per IP: 5 a minute, whatever the numbers.
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/waitlist', ['phone' => "+96777000000$i", 'governorate_id' => $this->city->id, 'district_id' => $this->soon->id])->assertCreated();
        }
        $this->postJson('/api/v1/waitlist', ['phone' => '+967770000009', 'governorate_id' => $this->city->id, 'district_id' => $this->soon->id])->assertStatus(429);

        // Per phone: the same number cannot be sprayed from many addresses.
        foreach (range(1, 5) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.$i"])
                ->postJson('/api/v1/waitlist', ['phone' => '+967 771 111 111', 'governorate_id' => $this->city->id, 'district_name' => "حي $i"])->assertCreated();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->postJson('/api/v1/waitlist', ['phone' => '+967771111111', 'governorate_id' => $this->city->id, 'district_name' => 'حي آخر'])->assertStatus(429);
    }

    public function test_public_zone_tree_lists_coming_soon_cities_without_private_data(): void
    {
        Governorate::create(['code' => 'aden', 'name_ar' => 'عدن', 'name_en' => 'Aden', 'is_active' => false]);
        ZoneWaitlist::create(['governorate_id' => $this->city->id, 'district_id' => $this->soon->id, 'phone' => '+967771234567']);

        $res = $this->getJson('/api/v1/zones')->assertOk()->assertJsonCount(2, 'data');

        $this->assertContains('coming_soon', array_column($res->json('data'), 'status'));
        $this->assertStringNotContainsString('771234567', $res->getContent());
    }

    // ---- admin: zones -------------------------------------------------------

    public function test_admin_zone_list_counts_verified_and_active_pros_per_district(): void
    {
        $this->pro(['base_district_id' => $this->live->id]);                                   // verified + active
        $this->pro(['base_district_id' => $this->live->id, 'is_accepting_jobs' => false]);     // verified, paused
        $this->pro(['base_district_id' => $this->live->id, 'kyc_status' => 'pending']);        // not verified
        $this->pro(['base_district_id' => $this->soon->id]);
        ZoneWaitlist::create(['governorate_id' => $this->city->id, 'district_id' => $this->soon->id, 'phone' => '+967771234567']);

        Sanctum::actingAs(User::factory()->dispatcher()->create());
        $city = collect($this->getJson('/api/v1/admin/zones')->assertOk()->json('data'))->firstWhere('id', $this->city->id);
        $live = collect($city['districts'])->firstWhere('id', $this->live->id);
        $soon = collect($city['districts'])->firstWhere('id', $this->soon->id);

        $this->assertSame([2, 1, 0], [$live['verified_pros'], $live['active_pros'], $live['waitlist_count']]);
        $this->assertSame([1, 1, 1], [$soon['verified_pros'], $soon['active_pros'], $soon['waitlist_count']]);
        $this->assertSame([3, 2, 1], [$city['verified_pros'], $city['active_pros'], $city['waitlist_count']]);
    }

    public function test_admin_can_launch_a_district_and_it_becomes_bookable(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->get('/'); // warm the zone cache, to prove it is invalidated

        $this->patchJson("/api/v1/admin/zones/districts/{$this->soon->id}", ['status' => 'active'])->assertOk();
        $this->postJson('/api/v1/bookings', $this->booking(['district_id' => $this->soon->id]))->assertCreated();

        $this->postJson('/api/v1/admin/zones/districts', ['governorate_id' => $this->city->id, 'name_ar' => 'معين', 'status' => 'coming_soon'])->assertCreated();
        $this->postJson('/api/v1/admin/zones/districts', ['governorate_id' => $this->city->id, 'name_ar' => 'معين', 'status' => 'active'])
            ->assertStatus(422)->assertJsonValidationErrors(['name_ar']);
        $this->patchJson("/api/v1/admin/zones/districts/{$this->soon->id}", ['status' => 'open'])->assertStatus(422);

        $this->patchJson("/api/v1/admin/zones/governorates/{$this->city->id}", ['is_active' => false])->assertOk();
        $this->postJson('/api/v1/bookings', $this->booking(['district_id' => $this->live->id]))->assertStatus(422);
    }

    public function test_a_district_in_use_cannot_be_deleted(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->pro(['base_district_id' => $this->live->id]);

        $this->deleteJson("/api/v1/admin/zones/districts/{$this->live->id}")->assertStatus(409);
        $this->deleteJson("/api/v1/admin/zones/districts/{$this->soon->id}")->assertOk();
        $this->assertDatabaseMissing('districts', ['id' => $this->soon->id]);
    }

    public function test_dispatchers_read_zones_but_only_admins_change_them(): void
    {
        ZoneWaitlist::create(['governorate_id' => $this->city->id, 'district_id' => $this->soon->id, 'phone' => '+967771234567']);
        Sanctum::actingAs(User::factory()->dispatcher()->create());

        $this->getJson('/api/v1/admin/zones')->assertOk();
        $this->getJson('/api/v1/admin/zones/waitlist')->assertOk()->assertJsonPath('data.0.phone', '+967771234567');
        $this->patchJson("/api/v1/admin/zones/districts/{$this->soon->id}", ['status' => 'active'])->assertForbidden();
        $this->postJson('/api/v1/admin/zones/districts', ['governorate_id' => $this->city->id, 'name_ar' => 'x', 'status' => 'active'])->assertForbidden();
        $this->patchJson("/api/v1/admin/zones/governorates/{$this->city->id}", ['is_active' => false])->assertForbidden();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/zones')->assertForbidden();
        $this->getJson('/api/v1/admin/zones/waitlist')->assertForbidden();
    }

    public function test_seeder_creates_the_new_launch_zones(): void
    {
        $this->seed([GovernorateSeeder::class, DistrictSeeder::class]);
        $capital = Governorate::where('code', 'amanat_al_asimah')->first();
        $sanaa = Governorate::where('code', 'sanaa')->first();

        // Every capital and Sanaa district is live: no district is held back.
        $this->assertTrue((bool) $capital->is_active);
        $this->assertTrue((bool) $sanaa->is_active);
        $this->assertSame(10, $capital->districts()->where('status', 'active')->count());
        $this->assertSame(15, $sanaa->districts()->where('status', 'active')->count());
        $this->assertSame(0, $capital->districts()->where('status', 'coming_soon')->count());
        $this->assertSame(0, District::whereHas('governorate', fn ($q) => $q->where('code', 'aden'))->count());
    }

    // ---- technician onboarding ---------------------------------------------

    public function test_join_page_explains_terms_and_old_url_redirects(): void
    {
        config(['platform.wallet.min_float' => 5000, 'platform.pricing.commission_percent' => 12]);

        $this->get('/join')->assertRedirect('/join-technician');
        $this->get('/join-technician')->assertOk()->assertInertia(fn ($page) => $page
            ->component('JoinTechnician')
            ->where('terms.min_float', 5000)
            ->where('terms.commission_percent', 12)
            ->where('application', null)
            ->has('categories', 1)
            ->has('governorates.0.districts', 2));
    }

    public function test_a_technician_registers_uploads_private_kyc_and_lands_in_the_review_queue(): void
    {
        Storage::fake('kyc');
        $user = User::factory()->create(['phone' => '+967711000111']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/trade/register', [
            'display_name' => 'أحمد السباك', 'primary_category_id' => $this->category->id,
            'base_governorate_id' => $this->city->id, 'base_district_id' => $this->soon->id,
        ])->assertCreated();

        $profile = $user->fresh()->tradespersonProfile;
        $this->assertSame($this->soon->id, $profile->base_district_id);
        $this->assertSame('tradesperson', $user->fresh()->role->value);
        // Each real request loads the user afresh; mirror that in the test.
        Sanctum::actingAs($user->fresh());

        foreach (['national_id_front', 'national_id_back', 'selfie'] as $type) {
            $this->post('/api/v1/trade/kyc/documents', ['type' => $type, 'file' => UploadedFile::fake()->image("$type.jpg", 60, 60)], ['Accept' => 'application/json'])
                ->assertCreated();
        }
        $this->postJson('/api/v1/trade/kyc/submit')->assertOk()->assertJsonPath('data.kyc_status', 'pending');

        // Stored on the private disk, under the profile, never on the public one.
        $doc = KycDocument::first();
        $this->assertSame('kyc', $doc->disk);
        Storage::disk('kyc')->assertExists($doc->path);
        $this->assertStringStartsWith("profile-{$profile->id}/", $doc->path);
        $this->assertSame('private', config('filesystems.disks.kyc.visibility'));

        // Visible to staff in the KYC queue.
        Sanctum::actingAs(User::factory()->dispatcher()->create());
        $this->getJson('/api/v1/admin/kyc/queue')->assertOk()
            ->assertJsonPath('data.0.id', $profile->id)
            ->assertJsonPath('data.0.kyc_documents_count', 3);

        // And the join page now shows the application instead of the form.
        $this->actingAs($user->fresh())->get('/join-technician')->assertInertia(fn ($page) => $page
            ->where('application.kyc_status', 'pending')->where('application.documents', 3));
    }

    public function test_registration_rejects_a_district_from_another_city(): void
    {
        $other = Governorate::create(['code' => 'aden', 'name_ar' => 'عدن', 'name_en' => 'Aden']);
        $foreign = District::create(['governorate_id' => $other->id, 'name_ar' => 'كريتر', 'status' => 'active']);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/trade/register', [
            'display_name' => 'فني', 'primary_category_id' => $this->category->id,
            'base_governorate_id' => $this->city->id, 'base_district_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['base_district_id']);
    }

    // ---- readiness indicator ------------------------------------------------

    public function test_readiness_needs_kyc_wallet_float_and_a_district(): void
    {
        config(['platform.wallet.min_float' => 5000]);
        $pro = $this->pro(['kyc_status' => 'pending']);
        $wallets = app(WalletService::class);
        $wallet = $wallets->walletFor($pro);

        $state = fn () => array_intersect_key($pro->fresh()->readiness(), array_flip(['kyc', 'wallet', 'district', 'ready']));

        $this->assertSame(['kyc' => false, 'wallet' => false, 'district' => false, 'ready' => false], $state());

        $pro->update(['kyc_status' => 'verified']);
        $this->assertSame(['kyc' => true, 'wallet' => false, 'district' => false, 'ready' => false], $state());

        $topup = fn (int $amount) => $wallets->confirmTopup(WalletTopup::create([
            'wallet_id' => $wallet->id, 'tradesperson_profile_id' => $pro->id, 'amount' => $amount, 'method' => 'bank_transfer', 'status' => 'confirmed',
        ]));
        $topup(4999);
        $this->assertFalse($state()['wallet'], 'One riyal short of the minimum float is not ready.');
        $topup(1);
        $this->assertTrue($state()['wallet']);

        $pro->update(['base_district_id' => $this->live->id]);
        $this->assertSame(['kyc' => true, 'wallet' => true, 'district' => true, 'ready' => true], $state());

        $wallet->update(['is_frozen' => true]);
        $this->assertFalse($state()['ready'], 'A frozen wallet cannot settle commission.');
    }

    public function test_admin_tradespeople_list_shows_readiness_and_is_staff_only(): void
    {
        config(['platform.wallet.min_float' => 5000]);
        $pro = $this->pro(['base_district_id' => $this->live->id], ['phone' => '+967711000111']);
        $this->getJson('/api/v1/admin/tradespeople')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/tradespeople')->assertForbidden();

        Sanctum::actingAs(User::factory()->dispatcher()->create());
        $this->getJson('/api/v1/admin/tradespeople')->assertOk()
            ->assertJsonPath('data.0.id', $pro->id)
            ->assertJsonPath('data.0.user.phone', '+967711000111')
            ->assertJsonPath('data.0.district.name_ar', 'السبعين')
            ->assertJsonPath('data.0.readiness.kyc', true)
            ->assertJsonPath('data.0.readiness.district', true)
            ->assertJsonPath('data.0.readiness.wallet', false)
            ->assertJsonPath('data.0.readiness.ready', false)
            ->assertJsonPath('data.0.readiness.min_float', 5000);
    }

    // ---- login redirect -----------------------------------------------------

    public function test_login_redirect_only_follows_paths_on_this_site(): void
    {
        $verify = function (string $redirect) {
            $otp = \App\Models\OtpCode::create([
                'email' => 'r'.uniqid().'@example.test', 'code_hash' => \Illuminate\Support\Facades\Hash::make('12345'), 'channel' => 'email',
                'purpose' => 'login', 'request_ip' => '127.0.0.1', 'session_token' => \Illuminate\Support\Str::random(48), 'expires_at' => now()->addMinutes(5),
            ]);
            $res = $this->post('/auth/otp/verify', ['session_token' => $otp->session_token, 'code' => '12345', 'redirect' => $redirect]);
            $this->post('/logout');

            return $res->headers->get('Location');
        };

        $this->assertStringEndsWith('/join-technician#apply', $verify('/join-technician#apply'));
        foreach (['https://evil.example/phish', '//evil.example', '/\\evil.example', 'javascript:alert(1)'] as $evil) {
            $this->assertStringEndsWith('/account', $verify($evil), "Followed an off-site redirect: $evil");
        }
    }
}
