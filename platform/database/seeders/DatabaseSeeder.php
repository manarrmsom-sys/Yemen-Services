<?php

namespace Database\Seeders;

use App\Enums\WalletType;
use App\Models\Wallet;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // System wallets must exist before any money moves.
        foreach ([WalletType::PlatformEscrow, WalletType::PlatformRevenue] as $type) {
            Wallet::firstOrCreate(
                ['owner_type' => null, 'owner_id' => null, 'type' => $type->value],
                ['currency' => config('platform.currency')],
            );
        }

        $this->call([
            GovernorateSeeder::class,
            CatalogSeeder::class,
            AdminUserSeeder::class,
        ]);

        if (app()->environment(['local', 'staging'])) {
            $this->call(DemoSeeder::class);
        }
    }
}
