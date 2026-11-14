<?php

namespace App\Models;

use App\Enums\KycStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class TradespersonProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'primary_category_id', 'base_governorate_id', 'base_district_id', 'display_name', 'image_url',
        'headline_ar', 'headline_en', 'bio', 'years_experience', 'service_governorate_ids',
        'skills', 'kyc_status', 'kyc_submitted_at', 'kyc_verified_at', 'kyc_reviewed_by',
        'kyc_rejection_reason', 'background_checked', 'badges', 'rating_avg', 'rating_count',
        'completed_orders', 'is_accepting_jobs',
    ];

    protected function casts(): array
    {
        return [
            'service_governorate_ids' => 'array',
            'skills' => 'array',
            'badges' => 'array',
            'kyc_status' => KycStatus::class,
            'kyc_submitted_at' => 'datetime',
            'kyc_verified_at' => 'datetime',
            'background_checked' => 'boolean',
            'is_accepting_jobs' => 'boolean',
            'rating_avg' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'primary_category_id');
    }

    public function baseGovernorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class, 'base_governorate_id');
    }

    public function baseDistrict(): BelongsTo
    {
        return $this->belongsTo(District::class, 'base_district_id');
    }

    /**
     * Go-live checklist shown to admins: a pro can actually take and settle COD
     * jobs only when identity is verified, the prepaid float covers commission,
     * and they are placed in a district.
     *
     * @return array{kyc: bool, wallet: bool, district: bool, ready: bool, balance: int, min_float: int}
     */
    public function readiness(): array
    {
        $minFloat = (int) config('platform.wallet.min_float');
        $balance = (int) ($this->wallet?->available_balance ?? 0);

        $checks = [
            'kyc' => $this->isVerified(),
            'wallet' => $balance >= $minFloat && ! ($this->wallet?->is_frozen ?? false),
            'district' => $this->base_district_id !== null,
        ];

        return $checks + [
            'ready' => ! in_array(false, $checks, true),
            'balance' => $balance,
            'min_float' => $minFloat,
        ];
    }

    public function kycDocuments(): HasMany
    {
        return $this->hasMany(KycDocument::class);
    }

    public function wallet(): MorphOne
    {
        return $this->morphOne(Wallet::class, 'owner');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function isVerified(): bool
    {
        return $this->kyc_status === KycStatus::Verified;
    }

    /** Recompute the badge cache from ground-truth fields. */
    public function recalculateBadges(): array
    {
        $badges = [];
        if ($this->isVerified()) {
            $badges[] = 'verified';
        }
        if ($this->background_checked) {
            $badges[] = 'background_checked';
        }
        if ($this->rating_count >= 10 && $this->rating_avg >= 4.6) {
            $badges[] = 'top_rated';
        }
        if ($this->completed_orders >= 50) {
            $badges[] = 'experienced';
        }

        $this->badges = $badges;

        return $badges;
    }
}
