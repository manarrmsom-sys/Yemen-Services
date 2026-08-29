<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InboundEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'source', 'external_id', 'signature_status', 'headers', 'payload',
        'handler', 'status', 'note', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
