<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\DispatchJob;
use App\Models\Governorate;
use App\Models\KycDocument;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\TradespersonProfile;
use App\Models\User;
use App\Models\WalletTopup;
use App\Services\Dispatch\DispatchService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Privacy and access-control regression suite. Each test pins one way the
 * platform could leak personal data or let someone act as somebody else.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_NAME = 'عميل سري';

    private const SECRET_PHONE = '+967733111222';

    private const SECRET_NOTES = 'الباب الخلفي مفتوح';

    private ServiceCategory $category;

    private Governorate $gov;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->category = ServiceCategory::create(['slug' => 'plumbing', 'name_ar' => 'سباكة', 'name_en' => 'Plumbing']);
        $this->gov = Governorate::create(['code' => 'sanaa', 'name_ar' => 'صنعاء', 'name_en' => 'Sanaa', 'dispatch_multiplier' => 1.0]);
        $this->service = Service::create([
            'service_category_id' => $this->category->id, 'slug' => 'leak', 'name_ar' => 'تسريب', 'name_en' => 'Leak',
            'pricing_model' => 'fixed', 'base_price' => 8000, 'callout_fee' => 2000, 'min_duration_minutes' => 60,
        ]);
    }

    private function pro(array $userAttrs = [], ?ServiceCategory $category = null): TradespersonProfile
    {
        $user = User::factory()->tradesperson()->create($userAttrs);

        return TradespersonProfile::create([
            'user_id' => $user->id, 'primary_category_id' => ($category ?? $this->category)->id, 'base_governorate_id' => $this->gov->id,
            'display_name' => 'فني', 'kyc_status' => 'verified', 'is_accepting_jobs' => true,
        ]);
    }

    private function order(array $attrs = []): Order
    {
        return Order::create($attrs + [
            'service_id' => $this->service->id, 'service_category_id' => $this->category->id, 'governorate_id' => $this->gov->id,
            'status' => OrderStatus::Dispatched, 'urgency' => 'today', 'subtotal' => 8000, 'total_estimate' => 10000,
            'commission_percent' => 12, 'commission_amount' => 960,
            'contact_name' => self::SECRET_NAME, 'contact_phone' => self::SECRET_PHONE, 'customer_notes' => self::SECRET_NOTES,
            'created_ip' => '203.0.113.9', 'price_breakdown' => ['lines' => []],
        ]);
    }

    private function assertNoPersonalData(string $body, array $extra = []): void
    {
        foreach (array_merge([self::SECRET_PHONE, ltrim(self::SECRET_PHONE, '+'), self::SECRET_NOTES, '203.0.113.9'], $extra) as $needle) {
            $this->assertStringNotContainsString($needle, $body, "Response leaked [$needle]");
        }
        // JSON escapes Arabic as \uXXXX; check the encoded form of the name too.
        $this->assertStringNotContainsString(trim(json_encode(self::SECRET_NAME), '"'), $body, 'Response leaked the customer name');
        $this->assertStringNotContainsString(self::SECRET_NAME, $body, 'Response leaked the customer name');
    }

    // =====================================================================
    // Email & contact privacy
    // =====================================================================

    public function test_user_model_never_serialises_contact_details_by_default(): void
    {
        $user = User::factory()->create(['email' => 'private@example.test', 'registration_ip' => '198.51.100.7']);
        $json = $user->fresh()->toJson();

        foreach (['private@example.test', $user->phone, '198.51.100.7', 'email_verified_at', 'phone_verified_at', 'remember_token'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_public_endpoints_never_expose_any_email_or_phone(): void
    {
        $pro = $this->pro(['email' => 'pro.private@example.test', 'phone' => '+967711000111']);
        User::factory()->create(['email' => 'someone@example.test']);

        foreach ([
            '/api/v1/tradespeople',
            "/api/v1/tradespeople/{$pro->id}",
            '/api/v1/catalog',
            '/api/v1/governorates',
        ] as $url) {
            $body = $this->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('@example.test', $body, "$url leaked an email");
            $this->assertStringNotContainsString('967711000111', $body, "$url leaked a phone");
        }

        foreach (['/', '/pros', '/join-technician', '/login'] as $url) {
            $body = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('@example.test', $body, "$url leaked an email");
            $this->assertStringNotContainsString('967711000111', $body, "$url leaked a phone");
        }
    }

    public function test_public_order_tracking_shows_status_only(): void
    {
        $pro = $this->pro(['email' => 'pro.private@example.test', 'phone' => '+967711000111']);
        $order = $this->order(['tradesperson_profile_id' => $pro->id, 'status' => OrderStatus::Accepted]);
        $order->events()->create(['type' => 'note', 'message' => 'internal: customer is difficult', 'created_at' => now()]);

        $res = $this->get("/track/{$order->reference}")->assertOk();
        $this->assertNoPersonalData($res->getContent(), ['internal: customer is difficult', 'pro.private@example.test', '967711000111', 'commission_amount']);

        $res->assertInertia(fn ($page) => $page
            ->where('order.reference', $order->reference)
            ->where('order.status', 'accepted')
            ->missing('order.contact_phone')->missing('order.contact_name')
            ->missing('order.customer_notes')->missing('order.commission_amount')->missing('order.id'));
    }

    public function test_tracking_lookups_are_rate_limited(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->get('/track/YS-NOPE'.$i)->assertOk();
        }
        $this->get('/track/YS-NOPE99')->assertStatus(429);
    }

    public function test_customer_sees_the_assigned_pros_phone_but_never_their_email(): void
    {
        $customer = User::factory()->create();
        $pro = $this->pro(['email' => 'pro.private@example.test', 'phone' => '+967711000111']);
        $staff = User::factory()->dispatcher()->create(['email' => 'staff.private@example.test']);
        $order = $this->order(['customer_id' => $customer->id, 'tradesperson_profile_id' => $pro->id, 'status' => OrderStatus::Accepted]);
        $order->events()->create(['type' => 'note', 'message' => 'internal note', 'actor_id' => $staff->id, 'created_at' => now()]);

        Sanctum::actingAs($customer);
        $body = $this->getJson("/api/v1/orders/{$order->reference}")->assertOk()
            ->assertJsonPath('data.tradesperson.user.phone', '+967711000111')
            ->getContent();

        $this->assertStringNotContainsString('@example.test', $body);
        $this->assertStringNotContainsString('internal note', $body);
        $this->assertStringNotContainsString('203.0.113.9', $body);
    }

    public function test_order_list_does_not_leak_ip_or_pro_contact(): void
    {
        $customer = User::factory()->create();
        $pro = $this->pro(['email' => 'pro.private@example.test', 'phone' => '+967711000111']);
        $this->order(['customer_id' => $customer->id, 'tradesperson_profile_id' => $pro->id]);

        Sanctum::actingAs($customer);
        $body = $this->getJson('/api/v1/orders')->assertOk()->getContent();

        foreach (['@example.test', '967711000111', '203.0.113.9', 'kyc_rejection_reason'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_open_jobs_hide_the_customer_until_a_pro_is_assigned(): void
    {
        $this->order();
        Sanctum::actingAs($this->pro()->user);

        $res = $this->getJson('/api/v1/trade/jobs')->assertOk()->assertJsonCount(1, 'data');
        $this->assertNoPersonalData($res->getContent());
        $res->assertJsonMissingPath('data.0.contact_phone')->assertJsonMissingPath('data.0.customer_notes');
    }

    public function test_kyc_documents_do_not_reveal_storage_paths_to_their_owner(): void
    {
        $pro = $this->pro();
        KycDocument::create([
            'tradesperson_profile_id' => $pro->id, 'type' => 'national_id_front', 'disk' => 'kyc',
            'path' => 'profile-1/secret-file.jpg', 'original_name' => 'id.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        Sanctum::actingAs($pro->user);
        $body = $this->getJson('/api/v1/trade/me')->assertOk()->getContent();

        $this->assertStringNotContainsString('secret-file.jpg', $body);
    }

    public function test_only_staff_see_contact_details_in_admin_endpoints(): void
    {
        $customer = User::factory()->create(['phone' => '+967700123456']);
        $order = $this->order(['customer_id' => $customer->id]);

        Sanctum::actingAs(User::factory()->dispatcher()->create());
        $this->getJson("/api/v1/admin/orders/{$order->reference}")->assertOk()
            ->assertJsonPath('data.customer.phone', '+967700123456');
    }

    // =====================================================================
    // Account takeover
    // =====================================================================

    public function test_email_cannot_be_changed_without_proving_ownership(): void
    {
        $attacker = User::factory()->create(['email' => 'attacker@example.test']);
        Sanctum::actingAs($attacker);

        $this->patchJson('/api/v1/profile', ['name' => 'New Name', 'email' => 'victim@example.test', 'phone' => '+967700000000', 'role' => 'admin'])
            ->assertOk();

        $attacker->refresh();
        $this->assertSame('attacker@example.test', $attacker->email, 'Squatting on a victim email must be impossible.');
        $this->assertSame('customer', $attacker->role->value, 'Role is not mass-assignable.');
        $this->assertSame('New Name', $attacker->name);
    }

    public function test_guest_order_can_only_be_claimed_with_a_verified_matching_phone(): void
    {
        $order = $this->order();

        Sanctum::actingAs(User::factory()->create(['phone' => '+967799999999']));
        $this->postJson("/api/v1/bookings/{$order->reference}/claim")->assertForbidden();

        // Same number but never verified (e.g. an email-only account that typed it in).
        Sanctum::actingAs(User::factory()->create(['phone' => self::SECRET_PHONE, 'phone_verified_at' => null]));
        $this->postJson("/api/v1/bookings/{$order->reference}/claim")->assertForbidden();
        User::where('phone', self::SECRET_PHONE)->delete();

        // Verified owner, number stored in a different format.
        $owner = User::factory()->create(['phone' => '967733111222']);
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/bookings/{$order->reference}/claim")->assertOk();
        $this->assertSame($owner->id, $order->fresh()->customer_id);
    }

    public function test_otp_verification_is_not_throttled_across_different_users(): void
    {
        // Each visitor verifies their own session token from their own address.
        // Before the fix they all shared one bucket and the third was locked out.
        foreach (range(1, 6) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.$i"])
                ->postJson('/api/v1/auth/otp/verify', ['session_token' => str_pad((string) $i, 48, 'x'), 'code' => '12345'])
                ->assertStatus(422);
        }
    }

    public function test_guessing_one_code_repeatedly_is_throttled(): void
    {
        $payload = ['session_token' => str_repeat('a', 48), 'code' => '00000'];

        $this->postJson('/api/v1/auth/otp/verify', $payload)->assertStatus(422);
        $this->postJson('/api/v1/auth/otp/verify', $payload)->assertStatus(422);
        $this->postJson('/api/v1/auth/otp/verify', $payload)->assertStatus(429);
    }

    public function test_requesting_a_code_does_not_reveal_whether_the_account_exists(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        User::factory()->create(['email' => 'known@example.test']);

        $known = $this->postJson('/api/v1/auth/otp/request', ['email' => 'known@example.test'])->assertOk()->json('data');
        $unknown = $this->postJson('/api/v1/auth/otp/request', ['email' => 'unknown@example.test'])->assertOk()->json('data');

        $this->assertSame(array_keys($known), array_keys($unknown));
        $this->assertSame(1, User::count(), 'No account is created until the code is verified.');
    }

    // =====================================================================
    // Authorisation (IDOR / privilege escalation)
    // =====================================================================

    public function test_a_customer_cannot_read_cancel_or_review_someone_elses_order(): void
    {
        $order = $this->order(['customer_id' => User::factory()->create()->id]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/orders/{$order->reference}")->assertForbidden();
        $this->postJson("/api/v1/orders/{$order->reference}/cancel")->assertForbidden();
        $this->postJson("/api/v1/orders/{$order->reference}/review", ['rating' => 1])->assertForbidden();
        $this->get("/account/orders/{$order->reference}")->assertForbidden();
    }

    public function test_a_user_cannot_edit_or_delete_another_users_address(): void
    {
        $victim = User::factory()->create();
        $address = $victim->addresses()->create(['governorate_id' => $this->gov->id, 'district' => 'حدة']);

        Sanctum::actingAs(User::factory()->create());
        $this->patchJson("/api/v1/addresses/{$address->id}", ['governorate_id' => $this->gov->id, 'district' => 'x'])->assertForbidden();
        $this->deleteJson("/api/v1/addresses/{$address->id}")->assertForbidden();
        $this->assertSame('حدة', $address->fresh()->district);
    }

    public function test_every_admin_api_route_rejects_guests_and_customers(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/admin'))
            ->map(fn ($r) => [$r->methods()[0], '/'.preg_replace('/\{[^}]+\}/', '1', $r->uri())]);

        $this->assertGreaterThan(20, $routes->count());

        foreach ($routes as [$method, $uri]) {
            $status = $this->json($method, $uri)->status();
            $this->assertSame(401, $status, "Guest reached $method $uri ($status)");
        }

        Sanctum::actingAs(User::factory()->create());
        foreach ($routes as [$method, $uri]) {
            $status = $this->json($method, $uri)->status();
            // 404 when the placeholder id does not exist (binding runs first); never a 2xx.
            $this->assertContains($status, [403, 404], "Customer reached $method $uri ($status)");
        }
    }

    public function test_admin_pages_redirect_guests_and_forbid_customers(): void
    {
        foreach (['/admin', '/admin/orders', '/admin/wallets', '/admin/kyc', '/admin/appearance', '/admin/integrations'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }

        $this->actingAs(User::factory()->create());
        foreach (['/admin', '/admin/orders', '/admin/wallets', '/admin/appearance'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_dispatchers_cannot_reach_admin_only_integrations(): void
    {
        Sanctum::actingAs(User::factory()->dispatcher()->create());

        $this->getJson('/api/v1/admin/webhooks')->assertForbidden();
        $this->getJson('/api/v1/admin/appearance')->assertForbidden();
        $this->get('/admin/integrations')->assertForbidden();
    }

    public function test_a_suspended_admin_is_locked_out(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(['status' => 'suspended']));

        $this->getJson('/api/v1/admin/orders')->assertForbidden();
    }

    public function test_unverified_tradespeople_cannot_see_or_take_jobs(): void
    {
        $pro = $this->pro();
        $pro->update(['kyc_status' => 'pending']);
        $order = $this->order();

        Sanctum::actingAs($pro->user);
        $this->getJson('/api/v1/trade/jobs')->assertForbidden();
        $this->postJson("/api/v1/trade/orders/{$order->reference}/accept")->assertForbidden();
        $this->assertNull($order->fresh()->tradesperson_profile_id);
    }

    public function test_a_pro_cannot_take_closed_assigned_or_out_of_category_orders(): void
    {
        $pro = $this->pro();
        Sanctum::actingAs($pro->user);

        $cancelled = $this->order(['status' => OrderStatus::Cancelled]);
        $this->postJson("/api/v1/trade/orders/{$cancelled->reference}/accept")->assertStatus(409);

        $taken = $this->order(['tradesperson_profile_id' => $this->pro()->id]);
        $this->postJson("/api/v1/trade/orders/{$taken->reference}/accept")->assertStatus(409);

        $other = ServiceCategory::create(['slug' => 'design', 'name_ar' => 'تصميم', 'name_en' => 'Design']);
        $foreign = $this->order(['service_category_id' => $other->id]);
        $this->postJson("/api/v1/trade/orders/{$foreign->reference}/accept")->assertForbidden();

        $this->assertNull($cancelled->fresh()->tradesperson_profile_id);
        $this->assertNull($foreign->fresh()->tradesperson_profile_id);
    }

    public function test_a_pro_cannot_change_the_status_of_another_pros_order(): void
    {
        $order = $this->order(['tradesperson_profile_id' => $this->pro()->id, 'status' => OrderStatus::Accepted]);

        Sanctum::actingAs($this->pro()->user);
        $this->postJson("/api/v1/trade/orders/{$order->reference}/status", ['status' => 'completed'])->assertForbidden();
        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
    }

    public function test_kyc_files_are_only_downloadable_by_staff(): void
    {
        Storage::fake('kyc');
        Storage::disk('kyc')->put('profile-1/id.jpg', 'binary');
        $pro = $this->pro();
        $doc = KycDocument::create([
            'tradesperson_profile_id' => $pro->id, 'type' => 'national_id_front', 'disk' => 'kyc',
            'path' => 'profile-1/id.jpg', 'original_name' => 'id.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 6,
        ]);
        $url = "/api/v1/admin/kyc/documents/{$doc->id}/file";

        $this->getJson($url)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs($pro->user);
        $this->getJson($url)->assertForbidden();

        // Private disk: not reachable as a static file either.
        foreach (['/storage/profile-1/id.jpg', '/storage/kyc/profile-1/id.jpg', '/kyc/profile-1/id.jpg'] as $path) {
            $this->assertContains($this->get($path)->status(), [403, 404], "$path must not be served");
        }
    }

    // =====================================================================
    // Webhook forgery
    // =====================================================================

    public function test_dispatch_links_need_a_valid_signature_bound_to_the_tradesperson(): void
    {
        $proA = $this->pro();
        $proB = $this->pro();
        $order = $this->order();
        DispatchJob::create(['order_id' => $order->id, 'mode' => 'broadcast', 'status' => 'sent', 'expires_at' => now()->addMinutes(30), 'attempt' => 1]);
        $base = "/webhooks/dispatch/{$order->reference}/accept";

        // No / wrong token (this used to be accepted everywhere except production).
        $this->get($base)->assertForbidden();
        $this->get("$base?t=deadbeef&tp={$proA->id}")->assertForbidden();

        // A's genuine link cannot be replayed to assign B.
        $tokenA = DispatchService::callbackToken($order->reference, 'accept', $proA->id);
        $this->get("$base?t=$tokenA&tp={$proB->id}")->assertForbidden();
        // ...nor can an accept token be used to decline.
        $this->get("/webhooks/dispatch/{$order->reference}/decline?t=$tokenA&tp={$proA->id}")->assertForbidden();
        $this->assertNull($order->fresh()->tradesperson_profile_id);

        $this->get("$base?t=$tokenA&tp={$proA->id}")->assertOk();
        $this->assertSame($proA->id, $order->fresh()->tradesperson_profile_id);
    }

    public function test_unsigned_automation_calls_cannot_confirm_money_outside_a_dev_machine(): void
    {
        $pro = $this->pro();
        $wallet = app(WalletService::class)->walletFor($pro);
        $topup = WalletTopup::create([
            'wallet_id' => $wallet->id, 'tradesperson_profile_id' => $pro->id, 'amount' => 50000,
            'method' => 'bank_transfer', 'reference_code' => 'TX-1', 'status' => 'pending',
        ]);
        $payload = ['reference_code' => 'TX-1'];

        // Staging / production with no secret configured: refuse.
        config(['platform.n8n.secret' => '']);
        $this->app->detectEnvironment(fn () => 'staging');
        $this->postJson('/webhooks/n8n/topup-matched', $payload)->assertUnauthorized();

        // Secret configured: a wrong signature is refused, the right one accepted.
        config(['platform.n8n.secret' => 'shared-secret']);
        $this->postJson('/webhooks/n8n/topup-matched', $payload, ['X-Signature' => 'forged'])->assertUnauthorized();
        $this->assertSame('pending', $topup->fresh()->status);
        $this->assertSame(0, $wallet->fresh()->available_balance);

        $signature = hash_hmac('sha256', json_encode($payload), 'shared-secret');
        $this->postJson('/webhooks/n8n/topup-matched', $payload, ['X-Signature' => $signature])->assertOk();
        $this->assertSame(50000, $wallet->fresh()->available_balance);
    }

    public function test_forged_whatsapp_replies_are_ignored_outside_a_dev_machine(): void
    {
        $pro = $this->pro(['phone' => '+967711222333']);
        $order = $this->order();
        DispatchJob::create(['order_id' => $order->id, 'mode' => 'broadcast', 'status' => 'sent', 'expires_at' => now()->addMinutes(30), 'attempt' => 1]);

        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'contacts' => [['wa_id' => '967711222333']],
            'messages' => [['from' => '967711222333', 'id' => 'wamid.x', 'type' => 'text', 'text' => ['body' => 'نعم']]],
        ]]]]]];

        config(['platform.whatsapp.app_secret' => 'meta-app-secret']);
        $this->app->detectEnvironment(fn () => 'staging');

        $this->postJson('/webhooks/whatsapp', $payload, ['X-Hub-Signature-256' => 'sha256=forged'])->assertOk();
        $this->assertNull($order->fresh()->tradesperson_profile_id, 'A forged "yes" must not assign the order.');
        $this->assertDatabaseHas('inbound_events', ['signature_status' => 'unverified', 'status' => 'ignored']);

        $signature = 'sha256='.hash_hmac('sha256', json_encode($payload), 'meta-app-secret');
        $this->postJson('/webhooks/whatsapp', $payload, ['X-Hub-Signature-256' => $signature])->assertOk();
        $this->assertSame($pro->id, $order->fresh()->tradesperson_profile_id);
    }

    public function test_whatsapp_webhook_handshake_needs_the_verify_token(): void
    {
        config(['platform.whatsapp.webhook_verify_token' => 'handshake']);

        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=1')->assertForbidden();
        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=handshake&hub_challenge=42')->assertOk()->assertSee('42');
    }

    // =====================================================================
    // Injection & input handling
    // =====================================================================

    public function test_sql_injection_attempts_are_treated_as_plain_text(): void
    {
        $order = $this->order();
        $evil = "' OR '1'='1";

        $this->get('/track/'.rawurlencode($evil))->assertOk()->assertInertia(fn ($p) => $p->where('order', null));
        $this->getJson('/api/v1/tradespeople?category_id='.rawurlencode('1 OR 1=1'))->assertOk();
        $this->getJson('/api/v1/catalog/services/'.rawurlencode("leak' UNION SELECT * FROM users--"))->assertNotFound();

        Sanctum::actingAs(User::factory()->dispatcher()->create());
        $this->getJson('/api/v1/admin/orders?q='.rawurlencode("%' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/orders?q='.rawurlencode($order->reference))->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame(1, Order::count());
    }

    public function test_script_tags_are_stripped_from_booking_text(): void
    {
        $res = $this->postJson('/api/v1/bookings', [
            'service_id' => $this->service->id, 'governorate_id' => $this->gov->id, 'urgency' => 'today',
            'district' => '<img src=x onerror=alert(1)>الحصبة', 'contact_name' => '<script>alert(1)</script>منار',
            'contact_phone' => '+967771234567', 'payment_method' => 'cod', 'notes' => '<b onmouseover=x>ملاحظة</b>',
        ])->assertCreated();

        $order = Order::where('reference', $res->json('data.order.reference'))->first();
        foreach ([$order->contact_name, $order->customer_notes, $order->address?->district] as $value) {
            $this->assertStringNotContainsString('<', (string) $value);
        }
    }

    public function test_booking_rejects_tampered_prices_and_unknown_fields(): void
    {
        $res = $this->postJson('/api/v1/bookings', [
            'service_id' => $this->service->id, 'governorate_id' => $this->gov->id, 'urgency' => 'today',
            'contact_name' => 'منار', 'contact_phone' => '+967771234567', 'payment_method' => 'cod',
            // Attempts to dictate money and ownership from the client:
            'total_estimate' => 1, 'subtotal' => 1, 'commission_amount' => 0, 'commission_percent' => 0,
            'status' => 'completed', 'tradesperson_profile_id' => 1, 'customer_id' => 1, 'discount' => 99999,
        ])->assertCreated();

        $order = Order::where('reference', $res->json('data.order.reference'))->first();
        $this->assertSame(10000, $order->total_estimate, 'Price is computed server-side.');
        $this->assertGreaterThan(0, $order->commission_amount);
        $this->assertNull($order->tradesperson_profile_id);
        $this->assertNull($order->customer_id);
        $this->assertNotSame(OrderStatus::Completed, $order->status);
    }

    public function test_uploads_must_be_real_images_or_pdfs(): void
    {
        Storage::fake('kyc');
        $pro = $this->pro();
        Sanctum::actingAs($pro->user);

        // Real files on disk, so the type is sniffed from the bytes exactly as in
        // production (fakes report whatever the file name claims).
        $real = function (string $name, string $content): \Illuminate\Http\UploadedFile {
            $path = tempnam(sys_get_temp_dir(), 'sec');
            file_put_contents($path, $content);

            return new \Illuminate\Http\UploadedFile($path, $name, null, null, true);
        };
        $php = $real('shell.php', '<?php system($_GET["c"]);');
        $disguised = $real('id.jpg', '<?php system($_GET["c"]);');
        $svg = $real('x.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');

        foreach ([$php, $disguised, $svg] as $file) {
            $this->post('/api/v1/trade/kyc/documents', ['type' => 'selfie', 'file' => $file], ['Accept' => 'application/json'])
                ->assertStatus(422);
        }
        $this->assertSame(0, KycDocument::count());

        $this->post('/api/v1/trade/kyc/documents', [
            'type' => 'selfie', 'file' => \Illuminate\Http\UploadedFile::fake()->image('me.jpg', 50, 50),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    // =====================================================================
    // Transport, headers, configuration
    // =====================================================================

    public function test_security_headers_are_present_on_pages(): void
    {
        $res = $this->get('/')->assertOk();

        $res->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $csp = $res->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp, 'unsafe-eval is a local-dev allowance only.');
    }

    public function test_state_changing_web_requests_need_a_csrf_token(): void
    {
        $this->withMiddleware();
        $response = $this->call('POST', '/auth/otp/request', ['email' => 'a@example.test']);

        // Testing disables CSRF by default; assert the middleware is wired on the web group.
        $middleware = app('router')->getMiddlewareGroups()['web'];
        $this->assertContains(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, $middleware);
        $this->assertNotSame(500, $response->status());
    }

    public function test_cross_origin_requests_from_unknown_sites_get_no_cors_grant(): void
    {
        $res = $this->getJson('/api/v1/catalog', ['Origin' => 'https://evil.example']);

        $this->assertNotSame('https://evil.example', $res->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $res->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_server_errors_do_not_leak_internals_when_debug_is_off(): void
    {
        config(['app.debug' => false]);
        \Illuminate\Support\Facades\Route::get('/api/v1/__boom', fn () => throw new \RuntimeException('SQLSTATE secret dsn mysql://root:pw@db'))->middleware('api');

        $body = $this->getJson('/api/v1/__boom')->assertStatus(500)->getContent();

        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('root:pw', $body);
        $this->assertStringNotContainsString('vendor', $body);
    }

    public function test_production_defaults_are_safe(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^APP_DEBUG=false$/m', $example);
        $this->assertMatchesRegularExpression('/^APP_ENV=production$/m', $example);
        $this->assertTrue(config('session.http_only'));
        $this->assertContains(config('session.same_site'), ['lax', 'strict']);
        $this->assertGreaterThanOrEqual(5, config('platform.otp.length'));
        $this->assertLessThanOrEqual(5, config('platform.otp.max_attempts'));

        // No secret is committed to the repository.
        foreach (['APP_KEY', 'DB_PASSWORD', 'WHATSAPP_ACCESS_TOKEN', 'WHATSAPP_APP_SECRET', 'N8N_WEBHOOK_SECRET', 'MAIL_PASSWORD', 'SMS_API_KEY'] as $key) {
            $this->assertMatchesRegularExpression('/^'.$key.'=\s*(#.*)?$/m', $example, "$key must be empty in .env.example");
        }
    }

    public function test_login_codes_are_stored_hashed_and_never_returned(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $res = $this->postJson('/api/v1/auth/otp/request', ['email' => 'a@example.test'])->assertOk();

        $otp = \App\Models\OtpCode::sole();
        $this->assertStringStartsWith('$2y$', $otp->code_hash);
        $this->assertStringNotContainsString('code_hash', $otp->toJson());
        $this->assertArrayNotHasKey('code', $res->json('data'));
    }
}
