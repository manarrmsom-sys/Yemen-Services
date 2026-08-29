<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class EstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'governorate_id' => ['required', 'integer', 'exists:governorates,id'],
            'urgency' => ['nullable', 'in:flexible,today,urgent'],
            'quantity' => ['nullable', 'numeric', 'min:0.5', 'max:9999'],
            'hours' => ['nullable', 'numeric', 'min:0.5', 'max:72'],
        ];
    }
}
