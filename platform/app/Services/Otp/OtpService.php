<?php

namespace App\Services\Otp;

use App\Enums\OtpChannel;
use App\Models\OtpCode;
use App\Services\Messaging\ChannelManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public function __construct(private readonly ChannelManager $channels) {}

    /**
     * Issue an OTP for a phone number and dispatch it over the chosen channel.
     *
     * @return array{session_token: string, expires_at: string, channel: string, resend_in: int}
     */
    /**
     * Work out which identity and channel a login request is for.
     * An email goes over email; a phone goes over WhatsApp, or SMS when WhatsApp
     * is switched off. Fails with a field error (422) rather than a 500.
     *
     * @param  array{email?: ?string, phone?: ?string, channel?: ?string}  $data
     * @return array{0: string, 1: string} [recipient, channel]
     */
    public function resolveRecipient(array $data): array
    {
        $enabled = fn (string $c) => (bool) config("platform.otp.channels.{$c}", false);

        $channel = $data['channel'] ?? null;
        if (! $channel) {
            $channel = filled($data['email'] ?? null) ? 'email' : ($enabled('whatsapp') ? 'whatsapp' : 'sms');
        }

        $field = $channel === 'email' ? 'email' : 'phone';
        if (blank($data[$field] ?? null)) {
            throw ValidationException::withMessages([$field => [__('auth.otp_identity_required')]]);
        }
        if (! $enabled($channel)) {
            throw ValidationException::withMessages([$field => [__('auth.otp_channel_disabled')]]);
        }

        return [$data[$field], $channel];
    }

    public function request(string $recipient, ?string $channel = null, ?string $ip = null, string $purpose = 'login'): array
    {
        $cfg = config('platform.otp');
        $channel = OtpChannel::tryFrom($channel ?? $cfg['default_channel']) ?? OtpChannel::from($cfg['default_channel']);
        if (! config("platform.otp.channels.{$channel->value}", false)) {
            // Refuse before a code is stored, so a disabled channel leaves no orphan OTP.
            throw ValidationException::withMessages([
                $channel === OtpChannel::Email ? 'email' : 'phone' => [__('auth.otp_channel_disabled')],
            ]);
        }
        $recipient = $channel === OtpChannel::Email ? strtolower(trim($recipient)) : $this->normalizePhone($recipient);
        $identityColumn = $channel === OtpChannel::Email ? 'email' : 'phone';

        // Resend cooldown — one active issuance per phone within the window.
        $recent = OtpCode::where($identityColumn, $recipient)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('created_at', '>', now()->subSeconds($cfg['resend_cooldown_seconds']))
            ->latest('id')
            ->first();

        if ($recent) {
            $wait = $cfg['resend_cooldown_seconds'] - now()->diffInSeconds($recent->created_at);
            throw ValidationException::withMessages([
                'phone' => [__('auth.otp_cooldown', ['seconds' => max(1, $wait)])],
            ]);
        }

        $code = $this->generateCode($cfg['length']);
        if (app()->environment('local')) {
            Log::info("OTP Code for {$recipient}: {$code}");
        }
        $sessionToken = Str::random(48);
        $expiresAt = now()->addSeconds($cfg['ttl_seconds']);

        // Invalidate previous unconsumed codes for this phone/purpose.
        OtpCode::where($identityColumn, $recipient)->where('purpose', $purpose)->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $otp = OtpCode::create([
            'phone' => $channel === OtpChannel::Email ? null : $recipient,
            'email' => $channel === OtpChannel::Email ? $recipient : null,
            'code_hash' => Hash::make($code),
            'channel' => $channel,
            'purpose' => $purpose,
            'request_ip' => $ip,
            'session_token' => $sessionToken,
            'expires_at' => $expiresAt,
        ]);

        try {
            $this->channels->for($channel)->send(
                $recipient,
                __('auth.otp_message', ['code' => $code, 'app' => config('app.name'), 'minutes' => (int) ($cfg['ttl_seconds'] / 60)]),
                [
                    'template' => $channel === OtpChannel::WhatsApp ? config('platform.whatsapp.otp_template') : null,
                    'params' => [$code],
                    'language' => app()->getLocale(),
                    'subject' => __('auth.otp_subject'),
                ],
            );
        } catch (\Throwable $e) {
            // A blocked SMTP port or a rejected API key is an operational issue:
            // report it as a field error (the visitor can retry) and never as a
            // bare 500. The code is consumed so an undeliverable one cannot stay
            // active and trigger the resend cooldown.
            $otp->update(['consumed_at' => now()]);
            Log::error('[otp] delivery failed', [
                'channel' => $channel->value,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                $identityColumn => [__('auth.otp_delivery_failed')],
            ]);
        }

        return [
            'session_token' => $sessionToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'channel' => $channel->value,
            'resend_in' => $cfg['resend_cooldown_seconds'],
        ];
    }

    /**
     * Verify a submitted code. Returns the normalized phone on success.
     */
    public function verify(string $sessionToken, string $code, ?string $ip = null): array
    {
        $cfg = config('platform.otp');

        $otp = OtpCode::where('session_token', $sessionToken)->latest('id')->first();

        if (! $otp || $otp->isConsumed()) {
            throw ValidationException::withMessages(['code' => [__('auth.otp_invalid')]]);
        }

        if ($otp->isExpired()) {
            throw ValidationException::withMessages(['code' => [__('auth.otp_expired')]]);
        }

        if ($otp->attempts >= $cfg['max_attempts']) {
            $otp->update(['consumed_at' => now()]);
            throw ValidationException::withMessages(['code' => [__('auth.otp_attempts')]]);
        }

        $otp->increment('attempts');

        if (! Hash::check($code, $otp->code_hash)) {
            throw ValidationException::withMessages(['code' => [__('auth.otp_invalid')]]);
        }

        $otp->update(['consumed_at' => now()]);

        return ['phone' => $otp->phone, 'email' => $otp->email];
    }

    public function throttleKey(string $scope, string $identifier): string
    {
        return "otp:{$scope}:".sha1($identifier);
    }

    public function assertNotRateLimited(string $key, int $maxPerMinute): void
    {
        if (RateLimiter::tooManyAttempts($key, $maxPerMinute)) {
            throw ValidationException::withMessages([
                'phone' => [__('auth.otp_throttled', ['seconds' => RateLimiter::availableIn($key)])],
            ]);
        }
        RateLimiter::hit($key, 60);
    }

    private function generateCode(int $length): string
    {
        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9+]/', '', $phone);
        $digits = ltrim($digits, '+');
        // Yemen: local numbers are 9 digits (7XXXXXXXX). Prefix country code if missing.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 9 && str_starts_with($digits, '7')) {
            $digits = '967'.$digits;
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '07')) {
            $digits = '967'.substr($digits, 1);
        }

        return '+'.$digits;
    }
}
