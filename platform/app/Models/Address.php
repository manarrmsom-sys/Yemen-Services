<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'governorate_id', 'label', 'district', 'street', 'building',
        'directions', 'lat', 'lng', 'contact_name', 'contact_phone', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'is_default' => 'boolean',
        ];
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function geoTags(): array
    {
        return [
            'governorate' => $this->governorate?->code,
            'district' => $this->district,
            'lat' => $this->lat,
            'lng' => $this->lng,
        ];
    }
}
