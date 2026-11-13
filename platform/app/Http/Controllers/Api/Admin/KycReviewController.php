<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Models\TradespersonProfile;
use App\Services\Kyc\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KycReviewController extends Controller
{
    public function __construct(private readonly KycService $kyc) {}

    public function queue(Request $request): JsonResponse
    {
        $profiles = TradespersonProfile::query()
            ->whereIn('kyc_status', ['pending', 'in_review'])
            ->with(['user:id,name,phone', 'primaryCategory:id,name_ar,name_en', 'baseGovernorate:id,name_ar,name_en'])
            ->withCount('kycDocuments')
            ->orderBy('kyc_submitted_at')
            ->paginate($request->integer('per_page', 20));

        $profiles->getCollection()->each(fn ($p) => $p->user?->makeVisible(\App\Models\User::CONTACT_FIELDS));

        return response()->json($profiles);
    }

    public function show(TradespersonProfile $profile): JsonResponse
    {
        $profile->load(['user', 'kycDocuments.reviewer:id,name', 'primaryCategory', 'baseGovernorate']);
        $profile->user?->makeVisible(\App\Models\User::CONTACT_FIELDS);

        return response()->json(['data' => $profile]);
    }

    /** Stream a private KYC document — never publicly served. */
    public function file(KycDocument $document): StreamedResponse
    {
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return Storage::disk($document->disk)->response(
            $document->path,
            $document->original_name,
            ['Content-Type' => $document->mime ?? 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function reviewDocument(Request $request, KycDocument $document): JsonResponse
    {
        $data = $request->validate([
            'approved' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $this->kyc->reviewDocument($document, $request->user(), $data['approved'], $data['note'] ?? null);

        return response()->json(['data' => $document->fresh()->only('id', 'review_status', 'review_note', 'reviewed_at')]);
    }

    public function decide(Request $request, TradespersonProfile $profile): JsonResponse
    {
        $data = $request->validate([
            'verified' => ['required', 'boolean'],
            'reason' => ['nullable', 'required_if:verified,false', 'string', 'max:400'],
            'background_checked' => ['nullable', 'boolean'],
        ]);

        $this->kyc->decide(
            $profile,
            $request->user(),
            $data['verified'],
            $data['reason'] ?? null,
            $data['background_checked'] ?? false,
        );

        return response()->json(['data' => [
            'kyc_status' => $profile->fresh()->kyc_status->value,
            'badges' => $profile->fresh()->badges,
        ]]);
    }
}
