<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['nullable', Rule::in(['email', 'whatsapp', 'sms'])],
            'email' => ['required_without:phone', 'nullable', 'email', 'max:255'],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
        ]);

        [$recipient, $channel] = $this->otp->resolveRecipient($data);
        $result = $this->otp->request($recipient, $channel, $request->ip());

        return response()->json(['data' => $result]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_token' => ['required', 'string', 'size:48'],
            'code' => ['required', 'string', 'min:4', 'max:8'],
            'name' => ['nullable', 'string', 'max:120'],
            'device_name' => ['nullable', 'string', 'max:60'],
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

        if (! $user->{$verifiedColumn}) {
            $user->forceFill([$verifiedColumn => now()])->save();
        }
        if (($data['name'] ?? null) && ! $user->name) {
            $user->update(['name' => $data['name']]);
        }

        $token = $user->createToken($data['device_name'] ?? 'pwa', ['*'], now()->addDays(30));

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'user' => $this->userPayload($user),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    private function userPayload(User $user): array
    {
        $user->loadMissing('tradespersonProfile');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'role' => $user->role->value,
            'preferred_locale' => $user->preferred_locale,
            'is_staff' => $user->isStaff(),
            'tradesperson' => $user->tradespersonProfile ? [
                'id' => $user->tradespersonProfile->id,
                'kyc_status' => $user->tradespersonProfile->kyc_status->value,
                'badges' => $user->tradespersonProfile->badges ?? [],
            ] : null,
        ];
    }
}
