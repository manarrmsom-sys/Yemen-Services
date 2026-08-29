<?php

namespace App\Services\Wallet;

use App\Models\Wallet;

/** One side of a ledger posting. */
final class LedgerLeg
{
    public function __construct(
        public readonly Wallet $wallet,
        public readonly string $direction,   // credit | debit
        public readonly string $bucket,      // available | pending | commission_due
        public readonly int $amount,         // positive integer YER
        public readonly string $type,        // order_earning | commission_charge | ...
        public readonly ?string $description = null,
        public readonly mixed $reference = null,
        public readonly array $meta = [],
    ) {}

    public function signedDelta(): int
    {
        return $this->direction === 'credit' ? $this->amount : -$this->amount;
    }
}
