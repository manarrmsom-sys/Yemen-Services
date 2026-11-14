<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\ZoneWaitlist;
use App\Services\Zones\ZoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ZoneController extends Controller
{
    public function __construct(private readonly ZoneService $zones) {}

    /**
     * Full zone tree (served + coming soon) for the waitlist form, with the
     * public vote count of every zone. Counts only — never who voted.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->zones->withVotes(app()->getLocale())]);
    }

    /**
     * Join the expansion waitlist for a zone that is not served yet.
     * Public and unauthenticated, so it is throttled per IP and per phone
     * (see the `waitlist` limiter) and idempotent per phone + zone.
     */
    public function joinWaitlist(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'name' => ['nullable', 'string', 'max:120'],
            'governorate_id' => ['required', 'integer', Rule::exists('governorates', 'id')],
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')->where('governorate_id', $request->input('governorate_id'))],
            'district_name' => ['nullable', 'string', 'max:80'],
            'service_category_id' => ['nullable', 'integer', Rule::exists('service_categories', 'id')],
        ]);

        // What can be voted for:
        //   - a coming-soon city: as a whole, one of its districts, or a named district;
        //   - a live city: only a district that is not live yet (listed or named).
        // A live city as a whole, or a live district, is already served.
        $districtId = $data['district_id'] ?? null;
        $cityLive = (bool) \App\Models\Governorate::whereKey($data['governorate_id'])->value('is_active');
        $districtLive = $districtId && District::whereKey($districtId)->active()->exists();

        if ($cityLive && ($districtLive || (! $districtId && blank($data['district_name'] ?? null)))) {
            throw ValidationException::withMessages(['district_id' => [__('errors.zone_already_served')]]);
        }

        $phone = '+'.ltrim(preg_replace('/\D/', '', $data['phone']), '0+');
        $clean = fn (?string $v) => blank($v) ? null : trim(strip_tags($v));

        $entry = ZoneWaitlist::firstOrCreate(
            [
                'phone' => $phone,
                'governorate_id' => $data['governorate_id'],
                'district_id' => $data['district_id'] ?? null,
                'district_name' => ($data['district_id'] ?? null) ? null : $clean($data['district_name'] ?? null),
            ],
            [
                'name' => $clean($data['name'] ?? null),
                'service_category_id' => $data['service_category_id'] ?? null,
            ],
        );

        if ($entry->wasRecentlyCreated) {
            foreach (config('app.supported_locales', ['ar', 'en']) as $locale) {
                \Illuminate\Support\Facades\Cache::forget("zones:votes:{$locale}");
            }
        }

        return response()->json([
            'data' => [
                'joined' => true,
                'already' => ! $entry->wasRecentlyCreated,
                'district' => $entry->district_id ? District::find($entry->district_id)?->localizedName() : $entry->district_name,
            ],
        ], $entry->wasRecentlyCreated ? 201 : 200);
    }
}
