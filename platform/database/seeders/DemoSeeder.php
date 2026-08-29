<?php

namespace Database\Seeders;

use App\Models\Governorate;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\TradespersonProfile;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Wallet\WalletService;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $wallets = app(WalletService::class);
        $orders = app(OrderService::class);

        $sanaa = Governorate::where('code', 'sanaa')->first();
        $aden = Governorate::where('code', 'aden')->first();
        $plumbing = ServiceCategory::where('slug', 'plumbing')->first();
        $electrical = ServiceCategory::where('slug', 'electrical')->first();

        $pros = [
            ['+967711000001', 'أحمد الحداد', $plumbing, $sanaa, 'verified', 4.8, 32],
            ['+967711000002', 'خالد الكهربائي', $electrical, $aden, 'verified', 4.9, 45],
            ['+967711000003', 'محمد النجار', $plumbing, $sanaa, 'pending', 0, 0],
        ];

        foreach ($pros as [$phone, $name, $cat, $gov, $kyc, $rating, $count]) {
            $user = User::updateOrCreate(['phone' => $phone], [
                'name' => $name, 'role' => 'tradesperson', 'status' => 'active',
                'preferred_locale' => 'ar', 'phone_verified_at' => now(),
            ]);

            $profile = TradespersonProfile::updateOrCreate(['user_id' => $user->id], [
                'primary_category_id' => $cat->id,
                'base_governorate_id' => $gov->id,
                'service_governorate_ids' => [$gov->id],
                'display_name' => $name,
                'headline_ar' => 'خبرة في '.$cat->name_ar,
                'headline_en' => 'Experienced in '.$cat->name_en,
                'years_experience' => 8,
                'kyc_status' => $kyc,
                'kyc_verified_at' => $kyc === 'verified' ? now() : null,
                'background_checked' => $kyc === 'verified',
                'rating_avg' => $rating,
                'rating_count' => $count,
                'completed_orders' => $count,
                'is_accepting_jobs' => true,
            ]);
            $profile->recalculateBadges();
            $profile->save();

            $w = $wallets->walletFor($profile);
            if ($kyc === 'verified') {
                // seed a commission float
                $topup = $w->topups()->create([
                    'tradesperson_profile_id' => $profile->id,
                    'amount' => 50000, 'currency' => 'YER', 'method' => 'bank_transfer',
                    'reference_code' => 'SEED-'.$profile->id, 'status' => 'pending',
                ]);
                $topup->update(['status' => 'confirmed', 'confirmed_at' => now()]);
                $wallets->confirmTopup($topup);
            }
        }

        // A couple of demo customers + orders.
        $customer = User::updateOrCreate(['phone' => '+967733000001'], [
            'name' => 'عميل تجريبي', 'role' => 'customer', 'status' => 'active', 'phone_verified_at' => now(),
        ]);

        $service = Service::where('slug', 'leak-repair')->first();
        $order = $orders->createFromBooking([
            'service_id' => $service->id,
            'governorate_id' => $sanaa->id,
            'district' => 'الحصبة',
            'urgency' => 'today',
            'contact_name' => $customer->name,
            'contact_phone' => $customer->phone,
            'payment_method' => 'cod',
        ], $customer);

        $verifiedPro = TradespersonProfile::where('kyc_status', 'verified')->first();
        $order->update(['tradesperson_profile_id' => $verifiedPro->id]);
        $orders->transition($order, \App\Enums\OrderStatus::Dispatched, null, 'Demo dispatch');
        $orders->transition($order, \App\Enums\OrderStatus::Accepted, null, 'Demo accept');
        $orders->transition($order, \App\Enums\OrderStatus::InProgress, null, 'Demo start');
        $orders->transition($order, \App\Enums\OrderStatus::Completed, null, 'Demo complete');
    }
}
