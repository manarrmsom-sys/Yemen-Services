<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TradespersonProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TradespersonAdminController extends Controller
{
    /** Every tradesperson with their go-live readiness. */
    public function index(Request $request): JsonResponse
    {
        $profiles = TradespersonProfile::query()
            ->with(['user:id,name,phone,email', 'wallet', 'primaryCategory:id,name_ar,name_en',
                'baseGovernorate:id,name_ar,name_en', 'baseDistrict:id,name_ar,name_en,status'])
            ->withCount('kycDocuments')
            ->when($request->string('q')->isNotEmpty(), fn ($q) => $q->where('display_name', 'like', '%'.$request->string('q').'%'))
            ->when($request->integer('district_id'), fn ($q, $id) => $q->where('base_district_id', $id))
            ->when($request->string('kyc')->isNotEmpty(), fn ($q) => $q->where('kyc_status', $request->string('kyc')))
            ->latest()
            ->paginate($request->integer('per_page', 30))
            ->through(function (TradespersonProfile $p) {
                $p->user?->makeVisible(User::CONTACT_FIELDS);

                return [
                    'id' => $p->id,
                    'display_name' => $p->display_name,
                    'image_url' => $p->image_url,
                    'headline_ar' => $p->headline_ar,
                    'headline_en' => $p->headline_en,
                    'kyc_status' => $p->kyc_status->value,
                    'kyc_documents_count' => $p->kyc_documents_count,
                    'is_accepting_jobs' => $p->is_accepting_jobs,
                    'rating_avg' => $p->rating_avg,
                    'rating_count' => $p->rating_count,
                    'completed_orders' => $p->completed_orders,
                    'user' => $p->user,
                    'category' => $p->primaryCategory?->only('name_ar', 'name_en'),
                    'governorate' => $p->baseGovernorate?->only('name_ar', 'name_en'),
                    'district' => $p->baseDistrict?->only('name_ar', 'name_en', 'status'),
                    'readiness' => $p->readiness(),
                ];
            });

        return response()->json($profiles);
    }
}
