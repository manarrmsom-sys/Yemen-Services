<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\B2bRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class B2bAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $leads = B2bRequest::query()
            ->with(['governorate:id,name_ar,name_en', 'district:id,name_ar,name_en', 'handler:id,name'])
            ->when($request->string('status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->string('type')->isNotEmpty(), fn ($q) => $q->where('business_type', $request->string('type')))
            ->latest()
            ->paginate($request->integer('per_page', 30));

        return response()->json($leads->toArray() + [
            'counts' => B2bRequest::selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function update(Request $request, B2bRequest $b2bRequest): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(B2bRequest::STATUSES)],
            'admin_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $b2bRequest->update($data + ['handled_by' => $request->user()->id, 'handled_at' => now()]);

        return response()->json(['data' => $b2bRequest->fresh(['governorate:id,name_ar,name_en', 'district:id,name_ar,name_en', 'handler:id,name'])]);
    }
}
