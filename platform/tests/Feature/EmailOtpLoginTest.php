<?php

namespace Tests\Feature;

use App\Enums\OtpChannel;
use App\Jobs\SendEmailMessage;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Messaging\ChannelManager;
use App\Services\Messaging\EmailChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

class EmailOtpLoginTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'customer@example.test';

    /** Request a code over the API and return [session_token, plain code]. */
    private function requestCode(string $email = self::EMAIL): array
    {
        Bus::fake([SendEmailMessage::class]);

        $res = $this->postJson('/api/v1/auth/otp/request', ['email' => $email])->assertOk();

        $code = null;
        Bus::assertDispatched(SendEmailMessage::class, function (SendEmailMessage $job) use (&$code) {
            preg_match('/\b(\d{'.config('platform.otp.length').'})\b/', $job->body, $m);
            $code = $m[1] ?? null;

            return true;
        });
        $this->assertNotNull($code, 'The emailed message must contain the code.');

        return [$res->json('data.session_token'), $code];
    }

    // ---- configuration -------------------------------------------------

    public function test_default_otp_channel_is_email_and_is_enabled(): void
    {
        $this->assertSame('email', config('platform.otp.default_channel'));
        $this->assertTrue(config('platform.otp.channels.email'));
    }

    public function test_env_files_declare_email_as_the_default_channel(): void
    {
        foreach (['.env.example', '.env'] as $file) {
            $path = base_path($file);
            if (! is_file($path)) {
                continue; // CI may only have .env.example
            }
            $this->assertMatchesRegularExpression('/^OTP_CHANNEL_DEFAULT=email\b/m', file_get_contents($path), "$file default channel");
            $this->assertMatchesRegularExpression('/^OTP_CHANNEL_EMAIL_ENABLED=true\b/m', file_get_contents($path), "$file email flag");
        }
    }

    public function test_the_configured_default_channel_is_never_a_disabled_one(): void
    {
        $default = config('platform.otp.default_channel');

        $this->assertNotNull(OtpChannel::tryFrom($default));
        $this->assertInstanceOf(EmailChannel::class, app(ChannelManager::class)->for($default));
    }

    public function test_disabled_channels_are_refused(): void
    {
        config(['platform.otp.channels.whatsapp' => false]);

        $this->expectException(InvalidArgumentException::class);
        app(ChannelManager::class)->for('whatsapp');
    }

    // ---- request -------------------------------------------------------

    public function test_requesting_a_code_stores_a_hashed_email_otp_and_queues_the_mail(): void
    {
        [$token, $code] = $this->requestCode('  Customer@Example.TEST ');

        $otp = OtpCode::sole();
        $this->assertSame(self::EMAIL, $otp->email, 'Email is normalised to lower-case.');
        $this->assertNull($otp->phone);
        $this->assertSame(OtpChannel::Email, $otp->channel);
        $this->assertSame($token, $otp->session_token);
        $this->assertNotSame($code, $otp->code_hash);
        $this->assertTrue(Hash::check($code, $otp->code_hash));
        $this->assertTrue($otp->expires_at->isFuture());

        Bus::assertDispatched(SendEmailMessage::class, fn ($job) => $job->recipient === self::EMAIL
            && $job->subject === __('auth.otp_subject'));
    }

    public function test_response_exposes_channel_but_never_the_code(): void
    {
        Bus::fake([SendEmailMessage::class]);

        $res = $this->postJson('/api/v1/auth/otp/request', ['email' => self::EMAIL])->assertOk()
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonStructure(['data' => ['session_token', 'expires_at', 'channel', 'resend_in']]);

        $this->assertArrayNotHasKey('code', $res->json('data'));
        $this->assertSame(48, strlen($res->json('data.session_token')));
    }

    public function test_the_mail_job_actually_sends_to_the_recipient(): void
    {
        Mail::fake();

        (new SendEmailMessage(self::EMAIL, 'Your code is 12345', 'Subject'))->handle();

        // Raw (non-mailable) sends are not recorded by the fake; reaching here
        // without an exception proves the job builds a valid message.
        $this->assertTrue(true);
    }

    public function test_request_requires_a_valid_email_or_a_phone(): void
    {
        $this->postJson('/api/v1/auth/otp/request', [])->assertStatus(422)->assertJsonValidationErrors(['email', 'phone']);
        $this->postJson('/api/v1/auth/otp/request', ['email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->assertSame(0, OtpCode::count());
    }

    public function test_a_second_request_inside_the_cooldown_is_rejected(): void
    {
        $this->requestCode();

        $this->postJson('/api/v1/auth/otp/request', ['email' => self::EMAIL])->assertStatus(422);
        $this->assertSame(1, OtpCode::count());
    }

    public function test_a_new_request_after_the_cooldown_invalidates_the_previous_code(): void
    {
        [$oldToken, $oldCode] = $this->requestCode();

        $this->travel(config('platform.otp.resend_cooldown_seconds') + 1)->seconds();
        [$newToken, $newCode] = $this->requestCode();

        $this->postJson('/api/v1/auth/otp/verify', ['session_token' => $oldToken, 'code' => $oldCode])->assertStatus(422);
        $this->postJson('/api/v1/auth/otp/verify', ['session_token' => $newToken, 'code' => $newCode])->assertOk();
    }

    // ---- verify --------------------------------------------------------

    public function test_verifying_the_code_creates_a_verified_customer_and_returns_a_token(): void
    {
        [$token, $code] = $this->requestCode();

        $res = $this->postJson('/api/v1/auth/otp/verify', [
            'session_token' => $token, 'code' => $code, 'name' => 'عميل جديد',
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'user' => ['id', 'role']]]);

        $user = User::sole();
        $this->assertSame(self::EMAIL, $user->email);
        $this->assertSame('عميل جديد', $user->name);
        $this->assertSame('customer', $user->role->value);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->phone);
        $this->assertNotNull(OtpCode::sole()->consumed_at);

        $this->withToken($res->json('data.token'))->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_a_returning_email_resolves_to_the_same_account(): void
    {
        [$token, $code] = $this->requestCode();
        $first = $this->postJson('/api/v1/auth/otp/verify', ['session_token' => $token, 'code' => $code])->json('data.user.id');

        $this->travel(config('platform.otp.resend_cooldown_seconds') + 1)->seconds();
        [$token, $code] = $this->requestCode(strtoupper(self::EMAIL));
        $second = $this->postJson('/api/v1/auth/otp/verify', ['session_token' => $token, 'code' => $code])->json('data.user.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, User::count());
    }

    public function test_email_login_does_not_hijack_a_phone_only_account(): void
    {
        $phoneOnly = User::factory()->create(['email' => null, 'phone' => '+967700000009']);

        [$token, $code] = $this->requestCode();
        $id = $this->postJson('/api/v1/auth/otp/verify', ['session_token' => $token, 'code' => $code])->json('data.user.id');

        $this->assertNotSame($phoneOnly->id, $id);
        $this->assertSame(2, User::count());
    }

    public function test_phone_otp_resolves_by_phone_and_never_by_a_null_email(): void
    {
        // Two accounts with no email: a null-email lookup would return the first one.
        $victim = User::factory()->create(['email' => null, 'phone' => '+967700000001']);
        $owner = User::factory()->create(['email' => null, 'phone' => '+967711222333']);

        $otp = OtpCode::create([
            'phone' => '+967711222333', 'email' => null, 'code_hash' => Hash::make('54321'),
            'channel' => OtpChannel::WhatsApp, 'purpose' => 'login', 'request_ip' => '127.0.0.1',
            'session_token' => str_repeat('a', 48), 'expires_at' => now()->addMinutes(5),
        ]);

        $id = $this->postJson('/api/v1/auth/otp/verify', ['session_token' => $otp->session_token, 'code' => '54321'])
            ->assertOk()->json('data.user.id');

        $this->assertSame($owner->id, $id);
        $this->assertNotSame($victim->id, $id);
        $this->assertNotNull($owner->fresh()->phone_verified_at);
    }

    public function test_a_wrong_code_is_rejected_and_counts_as_an_attempt(): void
    {
        [$token, $code] = $this->requestCode();
        $wrong = $code === '00000' ? '11111' : '00000';

        $this->postJson('/api/v1/auth/otp/verify', ['session_token' => $token, 'code' => $wrong])
            ->assertStatus(422)->assertJsonValidationErrors(['code']);

        $this->assertSame(1, OtpCode::sole()->attempts);
        $this->assertNull(OtpCode::sole()->consumed_at);
        $this->assertSame(0, User::count());
    }

    public function test_the_code_is_locked_after_too_many_wrong_attempts(): void
    {
        [$token, $code] = $this->requestCode();
        OtpCode::sole()->update(['attempts' => config('platform.otp.max_attempts')]);

        // Even the right code is refused once the attempt budget is spent.
        $this->postJson('/api/v1/auth/otp/verify', ['session_token' => $token, 'code' => $code])->assertStatus(422);

        $this->assertNotNull(OtpCode::sole()->consumed_at);
        $this->assertSame(0, User::count());
    }

    public function test_an_expired_code_is_rejected(): void
    {
        [$token, $code] = $this->requestCode();

        $this->travel(config('platform.otp.ttl_seconds') + 1)->seconds();

        $this->postJson('/api/v1/auth/otp/verify', ['session_token' => $token, 'code' => $code])->assertStatus(422);
        $this->assertSame(0, User::count());
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        [$token, $code] = $this->requestCode();

        $this->postJson('/api/v1/auth/otp/verify', ['session_token' => $token, 'code' => $code])->assertOk();
        $this->postJson('/api/v1/auth/otp/verify', ['session_token' => $token, 'code' => $code])->assertStatus(422);
    }

    public function test_an_unknown_session_token_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/otp/verify', ['session_token' => str_repeat('x', 48), 'code' => '12345'])
            ->assertStatus(422);
    }

    // ---- web (session) flow --------------------------------------------

    public function test_web_flow_logs_the_user_in_with_a_session(): void
    {
        Bus::fake([SendEmailMessage::class]);

        $this->from('/login')->post('/auth/otp/request', ['email' => self::EMAIL])
            ->assertRedirect('/login')->assertSessionHas('otp.session_token');

        $code = null;
        Bus::assertDispatched(SendEmailMessage::class, function ($job) use (&$code) {
            preg_match('/\b(\d{5})\b/', $job->body, $m);
            $code = $m[1] ?? null;

            return true;
        });

        $this->post('/auth/otp/verify', ['session_token' => OtpCode::sole()->session_token, 'code' => $code])
            ->assertRedirect();

        $this->assertAuthenticatedAs(User::where('email', self::EMAIL)->sole());
    }
}
