<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionEntry;
use App\Models\Wallet;
use App\Models\WalletTopup;
use App\Services\Webhooks\WebhookDispatcher;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletAdminController extends Controller
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly WebhookDispatcher $webhooks,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $wallets = Wallet::query()
            ->with('owner')
            ->when($request->string('type')->isNotEmpty(), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->boolean('frozen'), fn ($q) => $q->where('is_frozen', true))
            ->orderByDesc('commission_due')
            ->paginate($request->integer('per_page', 25));

        return response()->json($wallets);
    }

    public function show(Wallet $wallet): JsonResponse
    {
        $wallet->load('owner');

        return response()->json(['data' => $wallet]);
    }

    public function transactions(Request $request, Wallet $wallet): JsonResponse
    {
        return response()->json(
            $wallet->transactions()
                ->when($request->string('type')->isNotEmpty(), fn ($q) => $q->where('type', $request->string('type')))
                ->latest()
                ->paginate($request->integer('per_page', 30)),
        );
    }

    public function adjust(Request $request, Wallet $wallet): JsonResponse
    {
        $data = $request->validate([
            'bucket' => ['required', 'in:available,pending,commission_due'],
            'amount' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'max:300'],
        ]);

        $group = $this->wallets->adjust($wallet, $data['bucket'], $data['amount'], $data['reason']);

        return response()->json(['data' => ['group_uuid' => $group, 'wallet' => $wallet->fresh()]]);
    }

    public function freeze(Request $request, Wallet $wallet): JsonResponse
    {
        $data = $request->validate(['frozen' => ['required', 'boolean']]);
        $wallet->update(['is_frozen' => $data['frozen']]);

        return response()->json(['data' => $wallet]);
    }

    public function topups(Request $request): JsonResponse
    {
        $topups = WalletTopup::query()
            ->with(['profile:id,display_name', 'wallet:id,currency'])
            ->when($request->string('status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('status')), fn ($q) => $q->where('status', 'pending'))
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return response()->json($topups);
    }

    public function confirmTopup(Request $request, WalletTopup $topup): JsonResponse
    {
        abort_unless($topup->status === 'pending', 409, __('errors.topup_not_pending'));

        $topup->update([
            'status' => 'confirmed',
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => now(),
            'review_note' => $request->input('note'),
        ]);

        $group = $this->wallets->confirmTopup($topup);

        $this->webhooks->emit('wallet.topup_confirmed', [
            'topup_id' => $topup->id,
            'tradesperson_profile_id' => $topup->tradesperson_profile_id,
            'amount' => $topup->amount,
            'currency' => $topup->currency,
        ]);

        return response()->json(['data' => ['group_uuid' => $group, 'wallet' => $topup->wallet->fresh()]]);
    }

    public function rejectTopup(Request $request, WalletTopup $topup): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:300']]);
        abort_unless($topup->status === 'pending', 409);

        $topup->update([
            'status' => 'rejected',
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => now(),
            'review_note' => $data['note'],
        ]);

        return response()->json(['data' => $topup]);
    }

    public function commissions(Request $request): JsonResponse
    {
        $entries = CommissionEntry::query()
            ->with(['order:id,reference,total_estimate', 'profile:id,display_name'])
            ->when($request->string('status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 30));

        return response()->json($entries);
    }
}
