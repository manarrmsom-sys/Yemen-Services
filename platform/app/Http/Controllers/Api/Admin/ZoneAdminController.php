<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\Governorate;
use App\Models\TradespersonProfile;
use App\Models\ZoneWaitlist;
use App\Services\Zones\ZoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ZoneAdminController extends Controller
{
    public function __construct(private readonly ZoneService $zones) {}

    /** Governorates and districts with supply (pros) and demand (waitlist) counters. */
    public function index(): JsonResponse
    {
        $verified = fn ($q) => $q->where('kyc_status', 'verified');
        $active = fn ($q) => $q->where('kyc_status', 'verified')->where('is_accepting_jobs', true);

        $prosByGovernorate = TradespersonProfile::query()
            ->selectRaw('base_governorate_id, COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN kyc_status = 'verified' THEN 1 ELSE 0 END) as verified")
            ->selectRaw("SUM(CASE WHEN kyc_status = 'verified' AND is_accepting_jobs = 1 THEN 1 ELSE 0 END) as active")
            ->groupBy('base_governorate_id')->get()->keyBy('base_governorate_id');
        $waitlistByGovernorate = ZoneWaitlist::selectRaw('governorate_id, COUNT(*) as n')->groupBy('governorate_id')->pluck('n', 'governorate_id');

        $governorates = Governorate::query()
            ->with(['districts' => fn ($q) => $q->withCount([
                'tradespeople as verified_pros' => $verified,
                'tradespeople as active_pros' => $active,
                'waitlist as waitlist_count',
            ])])
            ->orderByDesc('is_active')->orderBy('name_ar')->get()
            ->map(fn (Governorate $g) => [
                'id' => $g->id,
                'name_ar' => $g->name_ar,
                'name_en' => $g->name_en,
                'is_active' => $g->is_active,
                'verified_pros' => (int) ($prosByGovernorate[$g->id]->verified ?? 0),
                'active_pros' => (int) ($prosByGovernorate[$g->id]->active ?? 0),
                'waitlist_count' => (int) ($waitlistByGovernorate[$g->id] ?? 0),
                'districts' => $g->districts->map(fn (District $d) => [
                    'id' => $d->id,
                    'name_ar' => $d->name_ar,
                    'name_en' => $d->name_en,
                    'status' => $d->status,
                    'sort_order' => $d->sort_order,
                    'verified_pros' => $d->verified_pros,
                    'active_pros' => $d->active_pros,
                    'waitlist_count' => $d->waitlist_count,
                ])->values(),
            ]);

        return response()->json(['data' => $governorates]);
    }

    public function updateGovernorate(Request $request, Governorate $governorate): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $governorate->update($data);
        $this->zones->flush();

        return response()->json(['data' => $governorate->only('id', 'is_active')]);
    }

    public function storeDistrict(Request $request): JsonResponse
    {
        $data = $request->validate([
            'governorate_id' => ['required', 'integer', Rule::exists('governorates', 'id')],
            'name_ar' => ['required', 'string', 'max:80', Rule::unique('districts')->where('governorate_id', $request->input('governorate_id'))],
            'name_en' => ['nullable', 'string', 'max:80'],
            'status' => ['required', Rule::in(District::STATUSES)],
        ]);

        $district = District::create($this->clean($data) + [
            'sort_order' => (int) District::where('governorate_id', $data['governorate_id'])->max('sort_order') + 1,
        ]);
        $this->zones->flush();

        return response()->json(['data' => $district], 201);
    }

    public function updateDistrict(Request $request, District $district): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['sometimes', 'string', 'max:80', Rule::unique('districts')->where('governorate_id', $district->governorate_id)->ignore($district->id)],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:80'],
            'status' => ['sometimes', Rule::in(District::STATUSES)],
            'sort_order' => ['sometimes', 'integer', 'between:0,9999'],
        ]);

        $district->update($this->clean($data));
        $this->zones->flush();

        return response()->json(['data' => $district]);
    }

    public function destroyDistrict(District $district): JsonResponse
    {
        // Tradespeople and waitlist entries point at it; deleting would silently
        // drop that link. Switch it to "coming soon" instead.
        abort_if($district->tradespeople()->exists() || $district->waitlist()->exists(), 409, __('errors.zone_in_use'));

        $district->delete();
        $this->zones->flush();

        return response()->json(['data' => ['ok' => true]]);
    }

    /** Waitlist entries, newest first. Phones are shown: this is a staff tool. */
    public function waitlist(Request $request): JsonResponse
    {
        $entries = ZoneWaitlist::query()
            ->with(['governorate:id,name_ar,name_en', 'district:id,name_ar,name_en', 'category:id,name_ar,name_en'])
            ->when($request->integer('governorate_id'), fn ($q, $id) => $q->where('governorate_id', $id))
            ->when($request->integer('district_id'), fn ($q, $id) => $q->where('district_id', $id))
            ->latest()
            ->paginate($request->integer('per_page', 50));

        return response()->json($entries);
    }

    private function clean(array $data): array
    {
        foreach (['name_ar', 'name_en'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $data[$field] = trim(strip_tags($data[$field]));
            }
        }

        return $data;
    }
}
