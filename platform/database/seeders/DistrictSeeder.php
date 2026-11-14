<?php

namespace Database\Seeders;

use App\Models\District;
use App\Models\Governorate;
use Illuminate\Database\Seeder;

class DistrictSeeder extends Seeder
{
    /**
     * Narrow launch: a handful of districts in the capital are live, the rest
     * are listed as "coming soon" so demand can be collected on the waitlist.
     * Which districts are live is a business decision — change it any time in
     * Admin > Cities & districts. Statuses set there are not overwritten here.
     *
     * A governorate is switched on only the first time its districts are
     * seeded, and only if one of them is active. Everything else stays off, so
     * the real launch is exactly the active districts below.
     */
    public function run(): void
    {
        $zones = [
            'amanat_al_asimah' => [
                ['السبعين', 'As Sabain', District::ACTIVE],
                ['معين', 'Ma\'ain', District::ACTIVE],
                ['الوحدة', 'Al Wahdah', District::ACTIVE],
                ['التحرير', 'At Tahrir', District::ACTIVE],
                ['الثورة', 'Ath Thawrah', District::COMING_SOON],
                ['شعوب', 'Shu\'ub', District::COMING_SOON],
                ['آزال', 'Azal', District::COMING_SOON],
                ['الصافية', 'As Safiyah', District::COMING_SOON],
                ['صنعاء القديمة', 'Old City', District::COMING_SOON],
                ['بني الحارث', 'Bani Al Harith', District::COMING_SOON],
            ],
            'aden' => [
                ['المنصورة', 'Al Mansoura', District::COMING_SOON],
                ['كريتر', 'Crater', District::COMING_SOON],
                ['خور مكسر', 'Khor Maksar', District::COMING_SOON],
                ['الشيخ عثمان', 'Sheikh Othman', District::COMING_SOON],
                ['المعلا', 'Al Mualla', District::COMING_SOON],
                ['التواهي', 'At Tawahi', District::COMING_SOON],
                ['دار سعد', 'Dar Saad', District::COMING_SOON],
                ['البريقة', 'Al Buraiqa', District::COMING_SOON],
            ],
        ];

        foreach ($zones as $code => $districts) {
            $governorate = Governorate::where('code', $code)->first();
            if (! $governorate) {
                continue;
            }

            $firstSeed = ! $governorate->districts()->exists();

            foreach ($districts as $i => [$ar, $en, $status]) {
                District::firstOrCreate(
                    ['governorate_id' => $governorate->id, 'name_ar' => $ar],
                    ['name_en' => $en, 'status' => $status, 'sort_order' => $i],
                );
            }

            if ($firstSeed) {
                $governorate->update(['is_active' => $governorate->districts()->where('status', District::ACTIVE)->exists()]);
            }
        }
    }
}
