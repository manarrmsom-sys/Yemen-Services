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
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'channel' => ['nullable', Rule::in(['whatsapp', 'sms'])],
        ]);

        $result = $this->otp->request($data['phone'], $data['channel'] ?? null, $request->ip());

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

        $phone = $this->otp->verify($data['session_token'], $data['code'], $request->ip());

        $user = User::firstOrCreate(
            ['phone' => $phone],
            [
                'name' => $data['name'] ?? null,
                'role' => 'customer',
                'status' => 'active',
                'preferred_locale' => app()->getLocale(),
                'registration_ip' => $request->ip(),
                'phone_verified_at' => now(),
            ],
        );

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended($data['redirect'] ?? route('account'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
