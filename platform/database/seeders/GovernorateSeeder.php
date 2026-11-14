<?php

namespace Database\Seeders;

use App\Models\Governorate;
use Illuminate\Database\Seeder;

class GovernorateSeeder extends Seeder
{
    public function run(): void
    {
        // code, ar, en, lat, lng, dispatch multiplier (remote/underserved cost more)
        $rows = [
            ['amanat_al_asimah', 'أمانة العاصمة', 'Amanat Al Asimah', 15.3694, 44.1910, 1.00],
            ['sanaa', 'صنعاء', 'Sana\'a', 15.5000, 44.2000, 1.05],
            ['aden', 'عدن', 'Aden', 12.7797, 45.0367, 1.00],
            ['taiz', 'تعز', 'Taiz', 13.5789, 44.0219, 1.10],
            ['hodeidah', 'الحديدة', 'Al Hudaydah', 14.7978, 42.9545, 1.10],
            ['ibb', 'إب', 'Ibb', 13.9667, 44.1833, 1.08],
            ['hadhramaut', 'حضرموت', 'Hadhramaut', 15.9300, 48.6300, 1.25],
            ['dhamar', 'ذمار', 'Dhamar', 14.5500, 44.4017, 1.08],
            ['marib', 'مأرب', 'Ma\'rib', 15.4600, 45.3200, 1.20],
            ['saada', 'صعدة', 'Saada', 16.9400, 43.7600, 1.30],
            ['al_bayda', 'البيضاء', 'Al Bayda', 13.9900, 45.5700, 1.20],
            ['lahij', 'لحج', 'Lahij', 13.0580, 44.8820, 1.10],
            ['al_dhale', 'الضالع', 'Al Dhale\'e', 13.6957, 44.7314, 1.15],
            ['shabwah', 'شبوة', 'Shabwah', 14.5300, 46.8300, 1.25],
            ['al_jawf', 'الجوف', 'Al Jawf', 16.6000, 45.0000, 1.35],
            ['amran', 'عمران', 'Amran', 15.6594, 43.9439, 1.12],
            ['al_mahwit', 'المحويت', 'Al Mahwit', 15.4700, 43.5450, 1.15],
            ['hajjah', 'حجة', 'Hajjah', 15.6943, 43.6050, 1.18],
            ['raymah', 'ريمة', 'Raymah', 14.6280, 43.7120, 1.20],
            ['al_mahrah', 'المهرة', 'Al Mahrah', 16.5200, 51.8300, 1.40],
            ['socotra', 'سقطرى', 'Socotra', 12.5000, 53.9000, 1.60],
            ['abyan', 'أبين', 'Abyan', 13.6300, 45.9600, 1.20],
        ];

        foreach ($rows as [$code, $ar, $en, $lat, $lng, $mult]) {
            // Created inactive: a governorate goes live only when it has an active
            // district (DistrictSeeder) or an admin switches it on. `is_active` is
            // never touched on re-seed, so launches made in the admin survive.
            $governorate = Governorate::firstOrCreate(['code' => $code], [
                'name_ar' => $ar, 'name_en' => $en, 'is_active' => false,
            ]);
            $governorate->update([
                'name_ar' => $ar, 'name_en' => $en,
                'center_lat' => $lat, 'center_lng' => $lng,
                'dispatch_multiplier' => $mult,
            ]);
        }
    }
}
