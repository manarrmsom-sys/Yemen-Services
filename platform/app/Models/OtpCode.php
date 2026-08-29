<?php

namespace App\Models;

use App\Enums\OtpChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class OtpCode extends Model
{
    use HasFactory, Prunable;

    /** Drop codes that are consumed or expired and older than a day. */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDay())
            ->where(fn ($q) => $q->whereNotNull('consumed_at')->orWhere('expires_at', '<', now()));
    }

    protected $fillable = [
        'phone', 'code_hash', 'channel', 'purpose', 'attempts',
        'request_ip', 'session_token', 'expires_at', 'consumed_at',
    ];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'channel' => OtpChannel::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
