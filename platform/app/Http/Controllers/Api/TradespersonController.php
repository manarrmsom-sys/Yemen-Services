<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\TradespersonProfile;
use App\Services\Kyc\KycService;
use App\Services\Orders\OrderService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TradespersonController extends Controller
{
    public function __construct(
        private readonly KycService $kyc,
        private readonly WalletService $wallets,
        private readonly OrderService $orders,
    ) {}

    // ---- Public discovery ----

    public function index(Request $request): JsonResponse
    {
        $locale = app()->getLocale();

        $query = TradespersonProfile::query()
            ->where('kyc_status', 'verified')
            ->where('is_accepting_jobs', true)
            ->with(['primaryCategory', 'baseGovernorate'])
            ->when($request->integer('category_id'), fn ($q, $id) => $q->where('primary_category_id', $id))
            ->when($request->integer('governorate_id'), fn ($q, $id) => $q->whereJsonContains('service_governorate_ids', $id)
                ->orWhere('base_governorate_id', $id))
            ->orderByDesc('rating_avg')
            ->orderByDesc('completed_orders');

        return response()->json($query->paginate(12)->through(fn ($p) => $this->publicCard($p, $locale)));
    }

    public function show(TradespersonProfile $profile): JsonResponse
    {
        abort_unless($profile->isVerified(), 404);
        $profile->load(['primaryCategory', 'baseGovernorate', 'reviews' => fn ($q) => $q->where('is_published', true)->latest()->limit(10)]);

        return response()->json(['data' => $this->publicCard($profile, app()->getLocale(), full: true)]);
    }

    // ---- Self-service onboarding ----

    public function register(Request $request): JsonResponse
    {
        abort_if($request->user()->tradespersonProfile, 409, __('errors.profile_exists'));

        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'primary_category_id' => ['required', 'integer', 'exists:service_categories,id'],
            'base_governorate_id' => ['required', 'integer', 'exists:governorates,id'],
            'service_governorate_ids' => ['nullable', 'array'],
            'service_governorate_ids.*' => ['integer', 'exists:governorates,id'],
            'headline_ar' => ['nullable', 'string', 'max:160'],
            'headline_en' => ['nullable', 'string', 'max:160'],
            'bio' => ['nullable', 'string', 'max:1500'],
            'years_experience' => ['nullable', 'integer', 'between:0,60'],
            'skills' => ['nullable', 'array'],
        ]);

        $profile = $request->user()->tradespersonProfile()->create($data + ['kyc_status' => 'unverified']);
        $this->wallets->walletFor($profile);
        $request->user()->update(['role' => 'tradesperson']);

        return response()->json(['data' => $profile], 201);
    }

    public function me(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        $profile->load(['kycDocuments', 'primaryCategory', 'baseGovernorate']);

        return response()->json(['data' => $profile]);
    }

    public function update(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        $this->authorize('update', $profile);

        $data = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:120'],
            'headline_ar' => ['sometimes', 'nullable', 'string', 'max:160'],
            'headline_en' => ['sometimes', 'nullable', 'string', 'max:160'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:1500'],
            'years_experience' => ['sometimes', 'integer', 'between:0,60'],
            'service_governorate_ids' => ['sometimes', 'array'],
            'skills' => ['sometimes', 'array'],
            'is_accepting_jobs' => ['sometimes', 'boolean'],
        ]);

        $profile->update($data);

        return response()->json(['data' => $profile]);
    }

    public function uploadKyc(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        $this->authorize('submitKyc', $profile);

        $data = $request->validate([
            'type' => ['required', Rule::in(['national_id_front', 'national_id_back', 'selfie', 'trade_certificate', 'commercial_register', 'other'])],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:8192'],
        ]);

        $doc = $this->kyc->storeDocument($profile, $data['type'], $request->file('file'));

        return response()->json(['data' => $doc->only('id', 'type', 'review_status', 'created_at')], 201);
    }

    public function submitKyc(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        $this->authorize('submitKyc', $profile);

        abort_if($profile->kycDocuments()->count() < 2, 422, __('errors.kyc_documents_required'));

        $this->kyc->submitForReview($profile);

        return response()->json(['data' => ['kyc_status' => $profile->fresh()->kyc_status->value]]);
    }

    // ---- Wallet ----

    public function wallet(Request $request): JsonResponse
    {
        $wallet = $this->wallets->walletFor($this->profile($request));

        return response()->json(['data' => [
            'currency' => $wallet->currency,
            'available_balance' => $wallet->available_balance,
            'pending_balance' => $wallet->pending_balance,
            'commission_due' => $wallet->commission_due,
            'lifetime_earnings' => $wallet->lifetime_earnings,
            'lifetime_commission_paid' => $wallet->lifetime_commission_paid,
            'is_frozen' => $wallet->is_frozen,
        ]]);
    }

    public function walletTransactions(Request $request): JsonResponse
    {
        $wallet = $this->wallets->walletFor($this->profile($request));

        return response()->json($wallet->transactions()->latest()->paginate(20));
    }

    public function requestTopup(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        $wallet = $this->wallets->walletFor($profile);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1000', 'max:100000000'],
            'method' => ['required', Rule::in(['bank_transfer', 'local_wallet', 'cash_office', 'card', 'other'])],
            'reference_code' => ['nullable', 'string', 'max:120'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:8192'],
        ]);

        $topup = $wallet->topups()->create([
            'tradesperson_profile_id' => $profile->id,
            'amount' => $data['amount'],
            'currency' => $wallet->currency,
            'method' => $data['method'],
            'reference_code' => $data['reference_code'] ?? null,
            'proof_path' => $request->file('proof')?->store("topup-{$profile->id}", 'kyc'),
            'status' => 'pending',
        ]);

        return response()->json(['data' => $topup->only('id', 'amount', 'status', 'created_at')], 201);
    }

    // ---- Jobs ----

    public function availableJobs(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        $govs = array_merge([$profile->base_governorate_id], (array) $profile->service_governorate_ids);

        $jobs = Order::query()
            ->whereIn('status', [OrderStatus::PendingDispatch->value, OrderStatus::Dispatched->value])
            ->whereNull('tradesperson_profile_id')
            ->where('service_category_id', $profile->primary_category_id)
            ->whereIn('governorate_id', array_filter($govs))
            ->with(['service', 'governorate'])
            ->latest()
            ->paginate(15);

        return response()->json($jobs);
    }

    public function acceptOrder(Request $request, Order $order): JsonResponse
    {
        $profile = $this->profile($request);
        abort_unless($order->tradesperson_profile_id === null, 409, __('errors.order_taken'));

        $order->update(['tradesperson_profile_id' => $profile->id]);
        $this->orders->transition($order, OrderStatus::Accepted, $request->user(), 'Accepted by tradesperson');

        return response()->json(['data' => $this->orders->summary($order->fresh())]);
    }

    public function updateOrderStatus(Request $request, Order $order): JsonResponse
    {
        $profile = $this->profile($request);
        abort_unless($order->tradesperson_profile_id === $profile->id, 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(['en_route', 'in_progress', 'completed'])],
            'total_final' => ['nullable', 'integer', 'min:0'],
        ]);

        if (($data['total_final'] ?? null) !== null) {
            $order->update(['total_final' => $data['total_final']]);
        }

        $this->orders->transition($order, OrderStatus::from($data['status']), $request->user());

        return response()->json(['data' => $this->orders->summary($order->fresh())]);
    }

    // ---- helpers ----

    private function profile(Request $request): TradespersonProfile
    {
        $profile = $request->user()->tradespersonProfile;
        abort_unless($profile, 404, __('errors.not_a_tradesperson'));

        return $profile;
    }

    private function publicCard(TradespersonProfile $p, string $locale, bool $full = false): array
    {
        $card = [
            'id' => $p->id,
            'display_name' => $p->display_name,
            'headline' => $locale === 'ar' ? $p->headline_ar : $p->headline_en,
            'category' => $p->primaryCategory ? ($locale === 'ar' ? $p->primaryCategory->name_ar : $p->primaryCategory->name_en) : null,
            'governorate' => $p->baseGovernorate?->localizedName($locale),
            'years_experience' => $p->years_experience,
            'rating_avg' => $p->rating_avg,
            'rating_count' => $p->rating_count,
            'completed_orders' => $p->completed_orders,
            'badges' => $p->badges ?? [],
        ];

        if ($full) {
            $card['bio'] = $p->bio;
            $card['skills'] = $p->skills ?? [];
            $card['reviews'] = $p->reviews->map(fn ($r) => [
                'rating' => $r->rating,
                'comment' => $r->comment,
                'created_at' => $r->created_at->toDateString(),
            ]);
        }

        return $card;
    }
}
