<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id', 'governorate_id', 'rule_type', 'value', 'applies_to',
        'conditions', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'conditions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    /** Does this rule apply given the estimator context? */
    public function matches(array $context): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->governorate_id && ($context['governorate_id'] ?? null) !== $this->governorate_id) {
            return false;
        }

        foreach ((array) $this->conditions as $key => $expected) {
            $actual = $context[$key] ?? null;
            if (is_array($expected)) {
                if (! in_array($actual, $expected, false)) {
                    return false;
                }
            } elseif ((string) $actual !== (string) $expected) {
                return false;
            }
        }

        return true;
    }
}
