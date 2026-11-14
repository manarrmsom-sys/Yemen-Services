<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends Model
{
    public const ACTIVE = 'active';

    public const COMING_SOON = 'coming_soon';

    public const STATUSES = [self::ACTIVE, self::COMING_SOON];

    protected $fillable = ['governorate_id', 'name_ar', 'name_en', 'status', 'sort_order'];

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function tradespeople(): HasMany
    {
        return $this->hasMany(TradespersonProfile::class, 'base_district_id');
    }

    public function waitlist(): HasMany
    {
        return $this->hasMany(ZoneWaitlist::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function localizedName(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->name_ar : ($this->name_en ?: $this->name_ar);
    }
}
