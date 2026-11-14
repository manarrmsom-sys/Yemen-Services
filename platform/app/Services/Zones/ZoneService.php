<?php

namespace App\Services\Zones;

use App\Models\District;
use App\Models\Governorate;
use Illuminate\Support\Facades\Cache;

/**
 * Launch zones: which governorates and districts are served now.
 *
 * Rule used across booking and onboarding:
 *   - a governorate must be active;
 *   - if it has districts defined, one of the *active* ones must be chosen;
 *   - a governorate with no districts defined is served as a whole (the
 *     pre-zones behaviour), so zones can be rolled out one city at a time.
 */
class ZoneService
{
    /** Active governorates with their districts, for the booking and join flows. */
    public function served(string $locale): array
    {
        return Cache::remember("zones:served:{$locale}", 900, fn () => $this->map(
            Governorate::where('is_active', true)->with('districts')->orderBy('name_'.$locale)->get(),
            $locale,
        ));
    }

    /** Every governorate, including coming-soon ones, for the waitlist form. */
    public function all(string $locale): array
    {
        return Cache::remember("zones:all:{$locale}", 900, fn () => $this->map(
            Governorate::with('districts')->orderByDesc('is_active')->orderBy('name_'.$locale)->get(),
            $locale,
        ));
    }

    public function flush(): void
    {
        foreach (config('app.supported_locales', ['ar', 'en']) as $locale) {
            Cache::forget("zones:served:{$locale}");
            Cache::forget("zones:all:{$locale}");
            Cache::forget("governorates:{$locale}");
        }
    }

    /**
     * Why a booking location cannot be served, or null when it can.
     * Returns a translation key suffix: governorate | district_required | district.
     */
    public function rejection(?int $governorateId, ?int $districtId): ?string
    {
        $governorate = $governorateId ? Governorate::find($governorateId) : null;
        if (! $governorate || ! $governorate->is_active) {
            return 'governorate';
        }

        if (! $governorate->districts()->exists()) {
            return null; // served as a whole
        }
        if (! $districtId) {
            return 'district_required';
        }

        $served = District::whereKey($districtId)->where('governorate_id', $governorate->id)->active()->exists();

        return $served ? null : 'district';
    }

    private function map($governorates, string $locale): array
    {
        return $governorates->map(fn (Governorate $g) => [
            'id' => $g->id,
            'code' => $g->code,
            'name' => $g->localizedName($locale),
            'status' => $g->is_active ? District::ACTIVE : District::COMING_SOON,
            'dispatch_multiplier' => $g->dispatch_multiplier,
            'districts' => $g->districts->map(fn (District $d) => [
                'id' => $d->id,
                'name' => $d->localizedName($locale),
                'status' => $d->status,
            ])->values()->all(),
        ])->values()->all();
    }
}
