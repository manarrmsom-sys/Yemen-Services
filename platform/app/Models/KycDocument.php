<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'tradesperson_profile_id', 'type', 'disk', 'path', 'original_name',
        'mime', 'size_bytes', 'review_status', 'review_note', 'reviewed_by', 'reviewed_at',
    ];

    /** Storage location is internal; files are only streamed through the gated admin route. */
    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(TradespersonProfile::class, 'tradesperson_profile_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
