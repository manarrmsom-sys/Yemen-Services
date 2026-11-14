<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class B2bRequest extends Model
{
    public const TYPES = ['hotel', 'restaurant', 'clinic', 'residential_compound', 'office', 'retail', 'school', 'other'];

    public const STATUSES = ['new', 'contacted', 'quoted', 'won', 'lost'];

    protected $table = 'b2b_requests';

    protected $fillable = [
        'reference', 'business_name', 'business_type', 'governorate_id', 'district_id', 'district_name',
        'facility_size', 'contact_name', 'phone', 'notes', 'status', 'admin_note', 'handled_by', 'handled_at',
    ];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (B2bRequest $request): void {
            $request->reference ??= static::generateReference();
        });
    }

    public static function generateReference(): string
    {
        do {
            $reference = 'B2B-'.strtoupper(Str::random(6));
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
