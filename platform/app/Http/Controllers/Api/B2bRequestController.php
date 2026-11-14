<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\B2bRequest;
use App\Services\Webhooks\N8nNotifier;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class B2bRequestController extends Controller
{
    public function __construct(
        private readonly WebhookDispatcher $webhooks,
        private readonly N8nNotifier $n8n,
    ) {}

    /**
     * Quote request for a recurring maintenance contract.
     * Public and unauthenticated: throttled per IP and per phone (`b2b` limiter).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:160'],
            'business_type' => ['required', Rule::in(B2bRequest::TYPES)],
            'governorate_id' => ['nullable', 'integer', Rule::exists('governorates', 'id')],
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')->where('governorate_id', $request->input('governorate_id'))],
            'district_name' => ['nullable', 'string', 'max:80', 'required_without:district_id'],
            'facility_size' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $clean = fn (?string $v) => blank($v) ? null : trim(strip_tags($v));
        foreach (['business_name', 'district_name', 'facility_size', 'contact_name', 'notes'] as $field) {
            $data[$field] = $clean($data[$field] ?? null);
        }
        $data['phone'] = '+'.ltrim(preg_replace('/\D/', '', $data['phone']), '0+');
        if ($data['district_id'] ?? null) {
            $data['district_name'] = null;
        }

        $lead = B2bRequest::create($data + ['status' => 'new']);
        $payload = $this->payload($lead);

        // Signed fan-out to every registered endpoint subscribed to the event...
        $this->webhooks->emit('b2b.request_created', $payload);
        // ...and a direct signed push to n8n, after the response so a slow
        // automation never delays the visitor.
        $url = config('platform.n8n.b2b_url');
        $n8n = $this->n8n;
        app()->terminating(fn () => $n8n->send($url, 'b2b.request_created', $payload));

        return response()->json(['data' => ['reference' => $lead->reference]], 201);
    }

    private function payload(B2bRequest $lead): array
    {
        $lead->loadMissing(['governorate:id,name_ar,name_en', 'district:id,name_ar,name_en']);

        return [
            'reference' => $lead->reference,
            'business_name' => $lead->business_name,
            'business_type' => $lead->business_type,
            'governorate' => $lead->governorate?->name_ar,
            'district' => $lead->district?->name_ar ?? $lead->district_name,
            'facility_size' => $lead->facility_size,
            'contact_name' => $lead->contact_name,
            'phone' => $lead->phone,
            'whatsapp_link' => 'https://wa.me/'.ltrim($lead->phone, '+'),
            'notes' => $lead->notes,
            'admin_url' => url('/admin/b2b'),
        ];
    }
}
