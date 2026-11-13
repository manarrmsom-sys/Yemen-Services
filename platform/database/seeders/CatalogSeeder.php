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
            ['plumbing', 'السباكة', 'Plumbing', '🔧', '#CE1126', 'https://images.unsplash.com/photo-1581578731548-c64695cc6952?auto=format&fit=crop&w=900&q=85', [
                ['leak-repair', 'إصلاح تسريب', 'Leak repair', 'fixed', ['base_price' => 6000, 'callout_fee' => 2000]],
                ['water-heater', 'تركيب سخان', 'Water heater install', 'fixed', ['base_price' => 9000, 'callout_fee' => 2000]],
                ['full-piping', 'تمديدات كاملة', 'Full re-piping', 'quote', ['callout_fee' => 3000]],
            ]],
            ['electrical', 'الكهرباء', 'Electrical', '⚡', '#CE1126', 'https://images.unsplash.com/photo-1621905252507-b35492cc74b4?auto=format&fit=crop&w=900&q=85', [
                ['fault-fix', 'إصلاح عطل', 'Fault diagnosis & fix', 'hourly', ['hourly_rate' => 4000, 'callout_fee' => 2000]],
                ['lighting', 'تركيب إنارة', 'Lighting installation', 'per_unit', ['unit_price' => 1500, 'unit_label_ar' => 'نقطة', 'unit_label_en' => 'point', 'callout_fee' => 2000]],
            ]],
            ['ac', 'التكييف والتبريد', 'AC & Cooling', '❄️', '#CE1126', 'https://images.unsplash.com/photo-1631545806609-2d4c09b6e5b3?auto=format&fit=crop&w=900&q=85', [
                ['ac-service', 'صيانة مكيف', 'AC service', 'per_unit', ['unit_price' => 5000, 'unit_label_ar' => 'وحدة', 'unit_label_en' => 'unit', 'callout_fee' => 2000]],
                ['ac-install', 'تركيب مكيف', 'AC installation', 'per_unit', ['unit_price' => 12000, 'unit_label_ar' => 'وحدة', 'unit_label_en' => 'unit', 'callout_fee' => 3000]],
            ]],
            ['carpentry', 'النجارة', 'Carpentry', '🪚', '#CE1126', 'https://images.unsplash.com/photo-1601058268499-e52658b8bb88?auto=format&fit=crop&w=900&q=85', [
                ['furniture-repair', 'إصلاح أثاث', 'Furniture repair', 'hourly', ['hourly_rate' => 3500, 'callout_fee' => 1500]],
                ['kitchen', 'تفصيل مطبخ', 'Fitted kitchen', 'quote', ['callout_fee' => 3000]],
            ]],
            ['cleaning', 'التنظيف', 'Cleaning', '🧽', '#CE1126', 'https://images.unsplash.com/photo-1581578731548-c64695cc6952?auto=format&fit=crop&w=900&q=85', [
                ['home-deep-clean', 'تنظيف عميق', 'Home deep clean', 'per_unit', ['unit_price' => 800, 'unit_label_ar' => 'م²', 'unit_label_en' => 'm²', 'callout_fee' => 1000]],
                ['tank-cleaning', 'تنظيف خزان', 'Water tank cleaning', 'fixed', ['base_price' => 7000, 'callout_fee' => 1000]],
            ]],
            ['painting', 'الدهان', 'Painting', '🎨', '#CE1126', 'https://images.unsplash.com/photo-1562259949-e8e7689d7828?auto=format&fit=crop&w=900&q=85', [
                ['interior-paint', 'دهان داخلي', 'Interior painting', 'per_unit', ['unit_price' => 600, 'unit_label_ar' => 'م²', 'unit_label_en' => 'm²', 'callout_fee' => 1500]],
            ]],
            ['appliances', 'صيانة الأجهزة', 'Appliance repair', '🔌', '#CE1126', 'https://images.unsplash.com/photo-1581092160607-ee22621dd758?auto=format&fit=crop&w=900&q=85', [
                ['washing-machine', 'غسالة', 'Washing machine repair', 'fixed', ['base_price' => 5000, 'callout_fee' => 2000]],
                ['fridge', 'ثلاجة', 'Refrigerator repair', 'fixed', ['base_price' => 6000, 'callout_fee' => 2000]],
            ]],
            ['commercial', 'خدمات الشركات', 'Commercial', '🏢', '#111315', 'https://images.unsplash.com/photo-1497366811353-6870744d04b2?auto=format&fit=crop&w=900&q=85', [
                ['office-maintenance', 'عقد صيانة مكتب', 'Office maintenance contract', 'quote', ['callout_fee' => 0]],
                ['generator-service', 'صيانة مولد', 'Generator service', 'hourly', ['hourly_rate' => 6000, 'callout_fee' => 3000]],
            ]],
            // ---- Digital & freelance work: delivered online (is_remote), so no
            // call-out fee and no governorate dispatch fee. Prices are starting
            // points for the client to review in Admin > Services.
            ['design', 'التصميم الجرافيكي', 'Graphic design', '🖌️', '#111315', 'https://images.unsplash.com/photo-1626785774573-4b799315345d?auto=format&fit=crop&w=900&q=85', [
                ['logo-design', 'تصميم شعار', 'Logo design', 'fixed', ['base_price' => 25000]],
                ['social-media-design', 'تصاميم سوشيال ميديا', 'Social media designs', 'per_unit', ['unit_price' => 3000, 'unit_label_ar' => 'تصميم', 'unit_label_en' => 'design']],
                ['brand-identity', 'هوية بصرية كاملة', 'Full brand identity', 'quote', []],
                ['print-design', 'تصميم مطبوعات وإعلانات', 'Print & ad design', 'per_unit', ['unit_price' => 5000, 'unit_label_ar' => 'تصميم', 'unit_label_en' => 'design']],
            ], true],
            ['programming', 'البرمجة والتطوير', 'Programming & development', '💻', '#111315', 'https://images.unsplash.com/photo-1461749280684-dccba630e2f6?auto=format&fit=crop&w=900&q=85', [
                ['landing-page', 'صفحة هبوط / موقع تعريفي', 'Landing page / brochure site', 'fixed', ['base_price' => 80000]],
                ['online-store', 'متجر إلكتروني', 'Online store', 'quote', []],
                ['mobile-app', 'تطبيق جوال', 'Mobile app', 'quote', []],
                ['bug-fixing', 'إصلاح أخطاء وصيانة برمجية', 'Bug fixing & maintenance', 'hourly', ['hourly_rate' => 8000]],
            ], true],
            ['video-editing', 'المونتاج والموشن', 'Video editing & motion', '🎬', '#111315', 'https://images.unsplash.com/photo-1574717024653-61fd2cf4d44d?auto=format&fit=crop&w=900&q=85', [
                ['reel-editing', 'مونتاج ريلز / مقطع قصير', 'Reel / short video edit', 'per_unit', ['unit_price' => 6000, 'unit_label_ar' => 'مقطع', 'unit_label_en' => 'video']],
                ['long-video-editing', 'مونتاج فيديو طويل', 'Long-form video edit', 'per_unit', ['unit_price' => 2500, 'unit_label_ar' => 'دقيقة', 'unit_label_en' => 'minute']],
                ['motion-graphics', 'موشن جرافيك', 'Motion graphics', 'per_unit', ['unit_price' => 1500, 'unit_label_ar' => 'ثانية', 'unit_label_en' => 'second']],
                ['voice-over', 'تعليق صوتي', 'Voice-over', 'per_unit', ['unit_price' => 4000, 'unit_label_ar' => 'دقيقة', 'unit_label_en' => 'minute']],
            ], true],
            ['digital-marketing', 'التسويق الرقمي', 'Digital marketing', '📣', '#111315', 'https://images.unsplash.com/photo-1432888622747-4eb9a8efeb07?auto=format&fit=crop&w=900&q=85', [
                ['social-media-management', 'إدارة حسابات التواصل (شهرياً)', 'Social media management (monthly)', 'fixed', ['base_price' => 60000]],
                ['ad-campaign', 'إعداد حملة إعلانية ممولة', 'Paid ad campaign setup', 'fixed', ['base_price' => 30000]],
                ['seo', 'تحسين محركات البحث', 'SEO', 'quote', []],
            ], true],
            ['writing-translation', 'الكتابة والترجمة', 'Writing & translation', '✍️', '#111315', 'https://images.unsplash.com/photo-1455390582262-044cdead277a?auto=format&fit=crop&w=900&q=85', [
                ['content-writing', 'كتابة محتوى', 'Content writing', 'per_unit', ['unit_price' => 3000, 'unit_label_ar' => 'صفحة', 'unit_label_en' => 'page']],
                ['translation', 'ترجمة', 'Translation', 'per_unit', ['unit_price' => 2500, 'unit_label_ar' => 'صفحة', 'unit_label_en' => 'page']],
                ['cv-writing', 'كتابة سيرة ذاتية', 'CV writing', 'fixed', ['base_price' => 8000]],
            ], true],
        ];

        foreach ($data as $i => $row) {
            [$slug, $ar, $en, $icon, $color, $image, $services] = $row;
            $category = ServiceCategory::updateOrCreate(['slug' => $slug], [
                'name_ar' => $ar, 'name_en' => $en, 'icon' => $icon, 'accent_color' => $color, 'image_url' => $image,
                'sort_order' => $i, 'requires_kyc' => true, 'is_remote' => $row[7] ?? false, 'is_active' => true,
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
