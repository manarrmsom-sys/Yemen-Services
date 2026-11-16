<?php

namespace Database\Seeders;

use App\Models\District;
use App\Models\Governorate;
use Illuminate\Database\Seeder;

class DistrictSeeder extends Seeder
{
    /**
     * Full launch: all ten districts of Amanat Al-Asimah and all fifteen of
     * Sanaa are live, so the two governorates can be booked directly with no
     * voting step. The remaining twenty governorates have no districts here
     * and stay coming soon; they are the only vote targets on /waitlist.
     *
     * Statuses are reapplied on every seed, so the launch list below is the
     * source of truth for the two served governorates.
     */
    public function run(): void
    {
        $zones = [
            'amanat_al_asimah' => [
                ['السبعين', 'As Sabain', District::ACTIVE],
                ['معين', 'Ma\'ain', District::ACTIVE],
                ['الوحدة', 'Al Wahdah', District::ACTIVE],
                ['التحرير', 'At Tahrir', District::ACTIVE],
                ['الصافية', 'As Safiyah', District::ACTIVE],
                ['شعوب', 'Shu\'ub', District::ACTIVE],
                ['الثورة', 'Ath Thawrah', District::ACTIVE],
                ['أزال', 'Azal', District::ACTIVE],
                ['بني الحارث', 'Bani Al Harith', District::ACTIVE],
                ['شملان/همدان', 'Shamlan/Hamdan', District::ACTIVE],
            ],
            'sanaa' => [
                ['سنحان وبني بهلول', 'Sanhan & Bani Bahloul', District::ACTIVE],
                ['بني مطر', 'Bani Matar', District::ACTIVE],
                ['همدان', 'Hamdan', District::ACTIVE],
                ['بني حشيش', 'Bani Hushaysh', District::ACTIVE],
                ['أرحب', 'Arhab', District::ACTIVE],
                ['بلاد الروس', 'Bilad Ar Rus', District::ACTIVE],
                ['خولان', 'Khawlan', District::ACTIVE],
                ['الحصن', 'Al Husn', District::ACTIVE],
                ['جحانة', 'Jihanah', District::ACTIVE],
                ['الطيال', 'At Tiyal', District::ACTIVE],
                ['نهم', 'Nihm', District::ACTIVE],
                ['الحيمة الداخلية', 'Al Haymah Al Dakhiliyah', District::ACTIVE],
                ['الحيمة الخارجية', 'Al Haymah Al Kharijiyah', District::ACTIVE],
                ['مناخة', 'Manakhah', District::ACTIVE],
                ['صعفان', 'Sa\'fan', District::ACTIVE],
            ],
        ];

        foreach ($zones as $code => $districts) {
            $governorate = Governorate::where('code', $code)->first();
            if (! $governorate) {
                continue;
            }

            foreach ($districts as $i => [$ar, $en, $status]) {
                District::updateOrCreate(
                    ['governorate_id' => $governorate->id, 'name_ar' => $ar],
                    ['name_en' => $en, 'status' => $status, 'sort_order' => $i],
                );
            }

            $governorate->update(['is_active' => true]);
            // Nothing in these two zones is held back: booking works everywhere.
            $governorate->districts()->update(['status' => District::ACTIVE]);
        }
    }
}
