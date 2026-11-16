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
    /**
     * Sanaa and Amanat Al-Asimah are served in full, so they must never appear
     * as vote targets: the waitlist is only for the other twenty governorates.
     */
    public const WAITLIST_EXCLUDED_CODES = ['amanat_al_asimah', 'sanaa'];

    /** Active governorates with their districts, for the booking and join flows. */
    public function served(string $locale): array
    {
        return Cache::remember("zones:served:{$locale}", 900, fn () => $this->map(
            Governorate::where('is_active', true)->with('districts')->orderBy('name_'.$locale)->get(),
            $locale,
        ));
    }

    /**
     * Every vote target: the twenty governorates still waiting for launch.
     * Sanaa and Amanat Al-Asimah are excluded — they are served directly.
     */
    public function all(string $locale): array
    {
        return Cache::remember("zones:all:{$locale}", 900, fn () => $this->map(
            Governorate::with('districts')
                ->whereNotIn('code', self::WAITLIST_EXCLUDED_CODES)
                ->orderByDesc('is_active')
                ->orderBy('name_'.$locale)
                ->get(),
            $locale,
        ));
    }

    /** Whether a governorate may be voted for on the expansion waitlist. */
    public function isVotableGovernorate(int $governorateId): bool
    {
        $code = Governorate::whereKey($governorateId)->value('code');

        return $code !== null && ! in_array($code, self::WAITLIST_EXCLUDED_CODES, true);
    }

    /**
     * The full tree plus how many people asked for each zone. A governorate's
     * count includes its districts and people who named no district.
     * Cached briefly: it is hit by a public campaign page.
     */
    public function withVotes(string $locale): array
    {
        return Cache::remember("zones:votes:{$locale}", 60, function () use ($locale): array {
            $byGovernorate = \App\Models\ZoneWaitlist::selectRaw('governorate_id, COUNT(*) as n')->groupBy('governorate_id')->pluck('n', 'governorate_id');
            $byDistrict = \App\Models\ZoneWaitlist::whereNotNull('district_id')->selectRaw('district_id, COUNT(*) as n')->groupBy('district_id')->pluck('n', 'district_id');

            $all = $this->all($locale);

            return array_map(function (array $g) use ($byGovernorate, $byDistrict) {
                $g['votes'] = (int) ($byGovernorate[$g['id']] ?? 0);
                $g['districts'] = array_map(fn (array $d) => $d + ['votes' => (int) ($byDistrict[$d['id']] ?? 0)], $g['districts']);

                return $g;
            }, $all);
        });
    }

    public function flush(): void
    {
        foreach (config('app.supported_locales', ['ar', 'en']) as $locale) {
            Cache::forget("zones:served:{$locale}");
            Cache::forget("zones:all:{$locale}");
            Cache::forget("zones:votes:{$locale}");
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
