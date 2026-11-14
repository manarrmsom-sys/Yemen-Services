<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Step 1 — service
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'quantity' => ['nullable', 'numeric', 'min:0.5', 'max:9999'],
            'hours' => ['nullable', 'numeric', 'min:0.5', 'max:72'],
            'options' => ['nullable', 'array'],
            'options.*' => ['string', 'max:120'],

            // Step 2 — where & when
            'governorate_id' => ['required', 'integer', 'exists:governorates,id'],
            'address_id' => ['nullable', 'integer', 'exists:addresses,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'district' => ['nullable', 'string', 'max:120'],
            'directions' => ['nullable', 'string', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'urgency' => ['required', 'in:flexible,today,urgent'],
            'scheduled_for' => ['nullable', 'date', 'after:now', 'before:+30 days'],

            // Step 3 — contact & payment
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'payment_method' => ['required', 'in:cod,local_wallet,bank_transfer,card'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'channel' => ['nullable', 'in:pwa,whatsapp,admin,phone'],
        ];
    }

    /**
     * On-site work can only be booked where the platform has launched.
     * Remote (digital) services are delivered online and skip the zone check.
     */
    public function after(): array
    {
        return [function (\Illuminate\Validation\Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (\App\Models\Service::with('category:id,is_remote')->find($this->service_id)?->category?->is_remote) {
                return;
            }

            $why = app(\App\Services\Zones\ZoneService::class)
                ->rejection((int) $this->governorate_id, $this->district_id ? (int) $this->district_id : null);

            if ($why) {
                $validator->errors()->add($why === 'governorate' ? 'governorate_id' : 'district_id', __('errors.zone_'.$why));
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'contact_name' => is_string($this->contact_name) ? trim(strip_tags($this->contact_name)) : $this->contact_name,
            'notes' => is_string($this->notes) ? trim(strip_tags($this->notes)) : $this->notes,
            'district' => is_string($this->district) ? trim(strip_tags($this->district)) : $this->district,
        ]);
    }
}
