<?php

namespace App\Models;

use App\Enums\DispatchStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'tradesperson_profile_id', 'mode', 'status', 'wa_deeplink',
        'payload', 'geo_tags', 'n8n_execution_id', 'attempt', 'sent_at',
        'responded_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DispatchStatus::class,
            'payload' => 'array',
            'geo_tags' => 'array',
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function tradesperson(): BelongsTo
    {
        return $this->belongsTo(TradespersonProfile::class, 'tradesperson_profile_id');
    }
}
