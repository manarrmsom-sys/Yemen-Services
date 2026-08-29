<?php

namespace App\Models;

use App\Enums\WalletType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_type', 'owner_id', 'type', 'currency', 'available_balance',
        'pending_balance', 'commission_due', 'lifetime_earnings',
        'lifetime_commission_paid', 'is_frozen',
    ];

    protected function casts(): array
    {
        return [
            'type' => WalletType::class,
            'available_balance' => 'integer',
            'pending_balance' => 'integer',
            'commission_due' => 'integer',
            'lifetime_earnings' => 'integer',
            'lifetime_commission_paid' => 'integer',
            'is_frozen' => 'boolean',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function topups(): HasMany
    {
        return $this->hasMany(WalletTopup::class);
    }

    public function bucketColumn(string $bucket): string
    {
        return match ($bucket) {
            'available' => 'available_balance',
            'pending' => 'pending_balance',
            'commission_due' => 'commission_due',
            default => throw new \InvalidArgumentException("Unknown wallet bucket [$bucket]"),
        };
    }
}
