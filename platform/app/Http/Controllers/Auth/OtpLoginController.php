<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Otp\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class OtpLoginController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    public function request(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'channel' => ['nullable', Rule::in(['email', 'whatsapp', 'sms'])],
            'email' => ['required_without:phone', 'nullable', 'email', 'max:255'],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
        ]);

        [$recipient, $channel] = $this->otp->resolveRecipient($data);
        $result = $this->otp->request($recipient, $channel, $request->ip());

        return back()->with([
            'success' => __('auth.otp_sent'),
            'otp' => $result,
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'session_token' => ['required', 'string', 'size:48'],
            'code' => ['required', 'string', 'min:4', 'max:8'],
            'name' => ['nullable', 'string', 'max:120'],
            'redirect' => ['nullable', 'string'],
        ]);

        $identity = $this->otp->verify($data['session_token'], $data['code'], $request->ip());

        // Resolve the account by whichever identity the OTP was issued for. Never
        // look up by a null column, or a phone login would match an arbitrary user.
        $viaEmail = filled($identity['email']);
        $verifiedColumn = $viaEmail ? 'email_verified_at' : 'phone_verified_at';

        $user = User::firstOrCreate(
            $viaEmail ? ['email' => $identity['email']] : ['phone' => $identity['phone']],
            [
                'name' => $data['name'] ?? null,
                'role' => 'customer',
                'status' => 'active',
                'preferred_locale' => app()->getLocale(),
                'registration_ip' => $request->ip(),
                $verifiedColumn => now(),
            ],
        );

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        // `redirect` comes from the query string: accept only a path on this site,
        // never an absolute or protocol-relative URL (open redirect).
        $target = (string) ($data['redirect'] ?? '');
        $safe = preg_match('#^/(?![/\\\\])[^\s]*$#', $target) === 1 ? $target : route('account');
        $fallback = $user->isStaff() ? route('admin.home') : $safe;

        return redirect()->intended($fallback);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
