<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\Urgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference', 'customer_id', 'service_id', 'service_category_id', 'governorate_id',
        'address_id', 'tradesperson_profile_id', 'status', 'channel', 'urgency', 'scheduled_for',
        'currency', 'subtotal', 'callout_fee', 'urgent_surcharge', 'governorate_adjustment',
        'vat_amount', 'discount', 'total_estimate', 'total_final', 'price_breakdown',
        'payment_method', 'payment_status', 'commission_percent', 'commission_amount',
        'customer_notes', 'estimator_inputs', 'contact_phone', 'contact_name',
        'dispatched_at', 'accepted_at', 'completed_at', 'cancelled_at', 'cancellation_reason',
        'created_ip',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'urgency' => Urgency::class,
            'scheduled_for' => 'datetime',
            'price_breakdown' => 'array',
            'estimator_inputs' => 'array',
            'subtotal' => 'integer',
            'callout_fee' => 'integer',
            'urgent_surcharge' => 'integer',
            'governorate_adjustment' => 'integer',
            'vat_amount' => 'integer',
            'discount' => 'integer',
            'total_estimate' => 'integer',
            'total_final' => 'integer',
            'commission_percent' => 'float',
            'commission_amount' => 'integer',
            'dispatched_at' => 'datetime',
            'accepted_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->reference ??= static::generateReference();
        });
    }

    public static function generateReference(): string
    {
        do {
            $ref = 'YS-'.strtoupper(Str::random(6));
        } while (static::where('reference', $ref)->exists());

        return $ref;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function tradesperson(): BelongsTo
    {
        return $this->belongsTo(TradespersonProfile::class, 'tradesperson_profile_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->latest('id');
    }

    public function dispatchJobs(): HasMany
    {
        return $this->hasMany(DispatchJob::class);
    }

    public function latestDispatch(): HasOne
    {
        return $this->hasOne(DispatchJob::class)->latestOfMany();
    }

    public function commissionEntry(): HasOne
    {
        return $this->hasOne(CommissionEntry::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            OrderStatus::Completed->value, OrderStatus::Closed->value,
            OrderStatus::Cancelled->value, OrderStatus::Expired->value,
        ]);
    }

    public function scopeInGovernorate(Builder $query, int $governorateId): Builder
    {
        return $query->where('governorate_id', $governorateId);
    }
}
