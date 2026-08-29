<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        // amounts are integer YER
        $data = [
            ['plumbing', 'السباكة', 'Plumbing', '🔧', '#CE1126', [
                ['leak-repair', 'إصلاح تسريب', 'Leak repair', 'fixed', ['base_price' => 6000, 'callout_fee' => 2000]],
                ['water-heater', 'تركيب سخان', 'Water heater install', 'fixed', ['base_price' => 9000, 'callout_fee' => 2000]],
                ['full-piping', 'تمديدات كاملة', 'Full re-piping', 'quote', ['callout_fee' => 3000]],
            ]],
            ['electrical', 'الكهرباء', 'Electrical', '⚡', '#CE1126', [
                ['fault-fix', 'إصلاح عطل', 'Fault diagnosis & fix', 'hourly', ['hourly_rate' => 4000, 'callout_fee' => 2000]],
                ['lighting', 'تركيب إنارة', 'Lighting installation', 'per_unit', ['unit_price' => 1500, 'unit_label_ar' => 'نقطة', 'unit_label_en' => 'point', 'callout_fee' => 2000]],
            ]],
            ['ac', 'التكييف والتبريد', 'AC & Cooling', '❄️', '#CE1126', [
                ['ac-service', 'صيانة مكيف', 'AC service', 'per_unit', ['unit_price' => 5000, 'unit_label_ar' => 'وحدة', 'unit_label_en' => 'unit', 'callout_fee' => 2000]],
                ['ac-install', 'تركيب مكيف', 'AC installation', 'per_unit', ['unit_price' => 12000, 'unit_label_ar' => 'وحدة', 'unit_label_en' => 'unit', 'callout_fee' => 3000]],
            ]],
            ['carpentry', 'النجارة', 'Carpentry', '🪚', '#CE1126', [
                ['furniture-repair', 'إصلاح أثاث', 'Furniture repair', 'hourly', ['hourly_rate' => 3500, 'callout_fee' => 1500]],
                ['kitchen', 'تفصيل مطبخ', 'Fitted kitchen', 'quote', ['callout_fee' => 3000]],
            ]],
            ['cleaning', 'التنظيف', 'Cleaning', '🧽', '#CE1126', [
                ['home-deep-clean', 'تنظيف عميق', 'Home deep clean', 'per_unit', ['unit_price' => 800, 'unit_label_ar' => 'م²', 'unit_label_en' => 'm²', 'callout_fee' => 1000]],
                ['tank-cleaning', 'تنظيف خزان', 'Water tank cleaning', 'fixed', ['base_price' => 7000, 'callout_fee' => 1000]],
            ]],
            ['painting', 'الدهان', 'Painting', '🎨', '#CE1126', [
                ['interior-paint', 'دهان داخلي', 'Interior painting', 'per_unit', ['unit_price' => 600, 'unit_label_ar' => 'م²', 'unit_label_en' => 'm²', 'callout_fee' => 1500]],
            ]],
            ['appliances', 'صيانة الأجهزة', 'Appliance repair', '🔌', '#CE1126', [
                ['washing-machine', 'غسالة', 'Washing machine repair', 'fixed', ['base_price' => 5000, 'callout_fee' => 2000]],
                ['fridge', 'ثلاجة', 'Refrigerator repair', 'fixed', ['base_price' => 6000, 'callout_fee' => 2000]],
            ]],
            ['commercial', 'خدمات الشركات', 'Commercial', '🏢', '#0F172A', [
                ['office-maintenance', 'عقد صيانة مكتب', 'Office maintenance contract', 'quote', ['callout_fee' => 0]],
                ['generator-service', 'صيانة مولد', 'Generator service', 'hourly', ['hourly_rate' => 6000, 'callout_fee' => 3000]],
            ]],
        ];

        foreach ($data as $i => [$slug, $ar, $en, $icon, $color, $services]) {
            $category = ServiceCategory::updateOrCreate(['slug' => $slug], [
                'name_ar' => $ar, 'name_en' => $en, 'icon' => $icon, 'accent_color' => $color,
                'sort_order' => $i, 'requires_kyc' => true, 'is_active' => true,
            ]);

            foreach ($services as $j => [$sSlug, $sAr, $sEn, $model, $pricing]) {
                Service::updateOrCreate(['slug' => $sSlug], array_merge([
                    'service_category_id' => $category->id,
                    'name_ar' => $sAr, 'name_en' => $sEn,
                    'pricing_model' => $model,
                    'is_active' => true, 'sort_order' => $j,
                    'min_duration_minutes' => 60,
                    'base_price' => 0, 'hourly_rate' => 0, 'unit_price' => 0, 'callout_fee' => 0,
                ], $pricing));
            }
        }
    }
}
