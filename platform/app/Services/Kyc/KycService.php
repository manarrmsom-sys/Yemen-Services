<?php

namespace App\Services\Kyc;

use App\Enums\KycStatus;
use App\Models\KycDocument;
use App\Models\TradespersonProfile;
use App\Models\User;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class KycService
{
    public function __construct(private readonly WebhookDispatcher $webhooks) {}

    public function storeDocument(TradespersonProfile $profile, string $type, UploadedFile $file): KycDocument
    {
        $path = $file->store("profile-{$profile->id}", 'kyc');

        return $profile->kycDocuments()->create([
            'type' => $type,
            'disk' => 'kyc',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'review_status' => 'pending',
        ]);
    }

    public function submitForReview(TradespersonProfile $profile): void
    {
        $profile->update([
            'kyc_status' => KycStatus::Pending,
            'kyc_submitted_at' => now(),
        ]);

        $this->webhooks->emit('kyc.submitted', [
            'tradesperson_profile_id' => $profile->id,
            'display_name' => $profile->display_name,
            'documents' => $profile->kycDocuments()->count(),
        ]);
    }

    public function reviewDocument(KycDocument $doc, User $reviewer, bool $approved, ?string $note = null): void
    {
        $doc->update([
            'review_status' => $approved ? 'approved' : 'rejected',
            'review_note' => $note,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $profile = $doc->profile;
        if ($profile->kyc_status === KycStatus::Pending) {
            $profile->update(['kyc_status' => KycStatus::InReview]);
        }
    }

    public function decide(TradespersonProfile $profile, User $reviewer, bool $verified, ?string $reason = null, bool $backgroundChecked = false): void
    {
        DB::transaction(function () use ($profile, $reviewer, $verified, $reason, $backgroundChecked) {
            $profile->update([
                'kyc_status' => $verified ? KycStatus::Verified : KycStatus::Rejected,
                'kyc_verified_at' => $verified ? now() : null,
                'kyc_reviewed_by' => $reviewer->id,
                'kyc_rejection_reason' => $verified ? null : $reason,
                'background_checked' => $backgroundChecked || $profile->background_checked,
            ]);

            $profile->recalculateBadges();
            $profile->save();

            if ($verified && $profile->user) {
                $profile->user->update(['status' => 'active', 'role' => 'tradesperson']);
            }
        });

        $this->webhooks->emit($verified ? 'kyc.verified' : 'kyc.rejected', [
            'tradesperson_profile_id' => $profile->id,
            'display_name' => $profile->display_name,
            'badges' => $profile->badges,
            'reason' => $verified ? null : $reason,
        ]);
    }
}
