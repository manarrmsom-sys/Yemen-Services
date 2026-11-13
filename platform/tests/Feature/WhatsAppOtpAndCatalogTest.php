<?php

namespace Tests\Feature;

use App\Models\Governorate;
use App\Models\OtpCode;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppOtpAndCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+967 774 274 848';

    private function enableWhatsApp(): void
    {
        config([
            'platform.otp.channels.whatsapp' => true,
            'platform.whatsapp.phone_number_id' => '1234567890',
            'platform.whatsapp.access_token' => 'test-token',
            'platform.whatsapp.api_base' => 'https://graph.facebook.com/v21.0',
            'platform.whatsapp.otp_template' => 'otp_login',
        ]);
    }

    /** Request a WhatsApp code and return [session_token, code Meta would deliver]. */
    private function requestCode(): array
    {
        $this->enableWhatsApp();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]], 200)]);

        $res = $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE, 'channel' => 'whatsapp'])
            ->assertOk()->assertJsonPath('data.channel', 'whatsapp');

        $sent = Http::recorded()->first()[0];
        $code = data_get($sent->data(), 'template.components.0.parameters.0.text');

        return [$res->json('data.session_token'), $code, $sent];
    }

    // ---- WhatsApp login ---------------------------------------------------

    public function test_whatsapp_code_is_sent_through_the_cloud_api_as_a_template(): void
    {
        [, $code, $sent] = $this->requestCode();

        $this->assertSame('https://graph.facebook.com/v21.0/1234567890/messages', $sent->url());
        $this->assertTrue($sent->hasHeader('Authorization', 'Bearer test-token'));
        $this->assertSame('967774274848', $sent['to'], 'Number is normalised to digits with country code.');
        $this->assertSame('template', $sent['type']);
        $this->assertSame('otp_login', $sent['template']['name']);
        $this->assertMatchesRegularExpression('/^\d{5}$/', $code);

        $otp = OtpCode::sole();
        $this->assertSame('967774274848', ltrim($otp->phone, '+'));
        $this->assertNull($otp->email);
    }

    public function test_verifying_a_whatsapp_code_signs_in_a_phone_verified_customer(): void
    {
        [$token, $code] = $this->requestCode();

        $res = $this->postJson('/api/v1/auth/otp/verify', ['session_token' => $token, 'code' => $code, 'name' => 'منار'])
            ->assertOk()->assertJsonStructure(['data' => ['token', 'user' => ['id']]]);

        $user = User::sole();
        $this->assertSame('967774274848', ltrim($user->phone, '+'));
        $this->assertNull($user->email);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertSame('منار', $user->name);

        $this->withToken($res->json('data.token'))->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_the_same_whatsapp_number_returns_to_the_same_account(): void
    {
        [$token, $code] = $this->requestCode();
        $first = $this->postJson('/api/v1/auth/otp/verify', ['session_token' => $token, 'code' => $code])->json('data.user.id');

        $this->travel(config('platform.otp.resend_cooldown_seconds') + 1)->seconds();
        [$token, $code] = $this->requestCode();
        $second = $this->postJson('/api/v1/auth/otp/verify', ['session_token' => $token, 'code' => $code])->json('data.user.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, User::count());
    }

    public function test_a_disabled_whatsapp_channel_is_a_clean_validation_error_with_no_orphan_code(): void
    {
        config(['platform.otp.channels.whatsapp' => false, 'platform.otp.channels.sms' => false]);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE, 'channel' => 'whatsapp'])
            ->assertStatus(422)->assertJsonValidationErrors(['phone']);

        $this->assertSame(0, OtpCode::count());
    }

    public function test_channel_and_identity_must_match(): void
    {
        $this->enableWhatsApp();

        // Email channel with only a phone used to crash with an undefined index.
        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE, 'channel' => 'email'])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->postJson('/api/v1/auth/otp/request', ['email' => 'a@example.test', 'channel' => 'whatsapp'])
            ->assertStatus(422)->assertJsonValidationErrors(['phone']);
    }

    public function test_a_meta_api_failure_does_not_pretend_the_code_was_sent(): void
    {
        $this->enableWhatsApp();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'bad token']], 401)]);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE, 'channel' => 'whatsapp'])
            ->assertStatus(500);
    }

    public function test_web_login_over_whatsapp_creates_a_session(): void
    {
        $this->enableWhatsApp();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]], 200)]);

        $this->from('/login')->post('/auth/otp/request', ['phone' => self::PHONE, 'channel' => 'whatsapp'])
            ->assertRedirect('/login')->assertSessionHas('otp.session_token');

        $code = data_get(Http::recorded()->first()[0]->data(), 'template.components.0.parameters.0.text');
        $this->post('/auth/otp/verify', ['session_token' => OtpCode::sole()->session_token, 'code' => $code])->assertRedirect();

        $this->assertAuthenticated();
    }

    public function test_support_number_and_enabled_channels_are_shared_with_the_frontend(): void
    {
        config(['platform.whatsapp.business_number' => '967774274848', 'platform.otp.channels.whatsapp' => true]);

        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page
            ->where('app.support_whatsapp', '967774274848')
            ->where('app.otp.channels', ['email', 'whatsapp']));
    }

    // ---- digital catalogue --------------------------------------------------

    public function test_seeder_adds_digital_categories_as_remote(): void
    {
        $this->seed(CatalogSeeder::class);

        foreach (['design', 'programming', 'video-editing', 'digital-marketing', 'writing-translation'] as $slug) {
            $category = ServiceCategory::where('slug', $slug)->first();
            $this->assertNotNull($category, "Missing digital category [$slug]");
            $this->assertTrue($category->is_remote);
            $this->assertGreaterThan(0, $category->services()->count());
            $this->assertSame(0, (int) $category->services()->sum('callout_fee'), 'Remote work has no call-out fee.');
        }

        $this->assertFalse(ServiceCategory::where('slug', 'plumbing')->value('is_remote') == true);
    }

    public function test_remote_services_are_not_charged_a_governorate_fee(): void
    {
        $gov = Governorate::create(['code' => 'far', 'name_ar' => 'بعيدة', 'name_en' => 'Far', 'dispatch_multiplier' => 1.5]);
        $make = fn (bool $remote, string $slug) => Service::create([
            'service_category_id' => ServiceCategory::create(['slug' => "c-$slug", 'name_ar' => 'ق', 'name_en' => 'C', 'is_remote' => $remote])->id,
            'slug' => $slug, 'name_ar' => 'خ', 'name_en' => 'S', 'pricing_model' => 'fixed', 'base_price' => 10000, 'callout_fee' => 0,
        ]);
        $estimate = fn (Service $s) => $this->postJson('/api/v1/estimate', ['service_id' => $s->id, 'governorate_id' => $gov->id, 'urgency' => 'today'])
            ->assertOk()->json('data.total');

        $this->assertSame(15000, $estimate($make(false, 'onsite')), 'On-site work pays the 1.5x governorate multiplier.');
        $this->assertSame(10000, $estimate($make(true, 'remote')), 'Remote work does not.');
    }
}
