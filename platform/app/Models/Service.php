<?php

namespace App\Models;

use App\Enums\PricingModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_category_id', 'slug', 'name_ar', 'name_en', 'description_ar', 'description_en',
        'pricing_model', 'base_price', 'hourly_rate', 'unit_price', 'unit_label_ar', 'unit_label_en',
        'callout_fee', 'min_duration_minutes', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'pricing_model' => PricingModel::class,
            'base_price' => 'integer',
            'hourly_rate' => 'integer',
            'unit_price' => 'integer',
            'callout_fee' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
