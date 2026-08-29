<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTopup extends Model
{
    use HasFactory;

    protected $fillable = [
        'wallet_id', 'tradesperson_profile_id', 'amount', 'currency', 'method',
        'reference_code', 'proof_path', 'status', 'review_note', 'confirmed_by',
        'confirmed_at', 'auto_matched_via',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(TradespersonProfile::class, 'tradesperson_profile_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
