<?php

namespace Tests\Feature;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The admin SPA talks to /api/v1/admin/* with the session cookie (Sanctum
 * stateful mode), on the same SQLite database as production. These tests walk
 * every page's primary read endpoint the way the browser does.
 */
class AdminPanelApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    /** One admin request per page in the dashboard, browser-style. */
    public function test_every_admin_page_endpoint_answers_200_over_the_session_guard(): void
    {
        $this->actingAs($this->admin);
        $this->withHeader('Referer', config('app.url').'/admin');

        $endpoints = [
            'dashboard' => '/api/v1/admin/dashboard/metrics',
            'dashboard live' => '/api/v1/admin/dashboard/live',
            'dispatch' => '/api/v1/admin/dispatch/board',
            'categories/services' => '/api/v1/admin/catalog',
            'technicians' => '/api/v1/admin/tradespeople',
            'zones' => '/api/v1/admin/zones',
            'zones waitlist' => '/api/v1/admin/zones/waitlist',
            'b2b' => '/api/v1/admin/b2b-requests',
            'appearance' => '/api/v1/admin/appearance',
            'orders' => '/api/v1/admin/orders',
            'wallets' => '/api/v1/admin/wallets',
            'kyc queue' => '/api/v1/admin/kyc/queue',
        ];

        foreach ($endpoints as $page => $url) {
            $this->getJson($url)->assertOk();
        }
    }

    /** The frontend served by the app's own domain is recognised as stateful. */
    public function test_the_app_domain_is_a_stateful_sanctum_domain(): void
    {
        $host = parse_url(config('app.url'), PHP_URL_HOST);

        $this->assertContains($host, config('sanctum.stateful'), 'The APP_URL host must be stateful for session auth.');
    }

    public function test_a_dispatcher_reaches_the_read_endpoints_but_not_admin_only_ones(): void
    {
        $this->actingAs(User::factory()->dispatcher()->create());

        $this->getJson('/api/v1/admin/dashboard/metrics')->assertOk();
        $this->getJson('/api/v1/admin/appearance')->assertForbidden();
    }

    public function test_an_otp_signup_is_only_ever_a_customer_and_cannot_reach_the_admin_api(): void
    {
        $otp = OtpCode::create([
            'email' => 'newcomer@example.test',
            'code_hash' => Hash::make('12345'),
            'channel' => 'email',
            'purpose' => 'login',
            'request_ip' => '127.0.0.1',
            'session_token' => Str::random(48),
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->post('/auth/otp/verify', [
            'session_token' => $otp->session_token,
            'code' => '12345',
            'name' => 'زائر جديد',
        ])->assertRedirect(route('account'));

        $user = User::where('email', 'newcomer@example.test')->sole();
        $this->assertSame('customer', $user->role->value);

        $this->actingAs($user)->getJson('/api/v1/admin/dashboard/metrics')->assertForbidden();
        $this->get('/admin')->assertForbidden();
    }

    public function test_an_otp_identity_cannot_escalate_an_existing_account_role(): void
    {
        $existing = User::factory()->create(['email' => 'customer@example.test', 'role' => 'customer']);

        $otp = OtpCode::create([
            'email' => 'customer@example.test',
            'code_hash' => Hash::make('12345'),
            'channel' => 'email',
            'purpose' => 'login',
            'request_ip' => '127.0.0.1',
            'session_token' => Str::random(48),
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->post('/auth/otp/verify', ['session_token' => $otp->session_token, 'code' => '12345'])->assertRedirect();

        $this->assertSame('customer', $existing->fresh()->role->value);
    }
}
