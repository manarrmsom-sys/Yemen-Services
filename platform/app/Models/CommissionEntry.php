<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'tradesperson_profile_id', 'order_subtotal', 'commission_percent',
        'commission_amount', 'settlement', 'status', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'order_subtotal' => 'integer',
            'commission_percent' => 'float',
            'commission_amount' => 'integer',
            'settled_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(TradespersonProfile::class, 'tradesperson_profile_id');
    }
}
