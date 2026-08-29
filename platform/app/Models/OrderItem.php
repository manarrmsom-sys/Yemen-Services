<?php

namespace App\Models;

use App\Enums\PricingModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'service_id', 'name_snapshot', 'pricing_model',
        'quantity', 'unit_price', 'line_total', 'options',
    ];

    protected function casts(): array
    {
        return [
            'pricing_model' => PricingModel::class,
            'quantity' => 'float',
            'unit_price' => 'integer',
            'line_total' => 'integer',
            'options' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
