<?php

namespace Tests\Unit;

use App\Models\Wallet;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class WalletPaiseCalculationTest extends TestCase
{
    public function test_rupees_to_paise_handles_floating_point_edge_cases_exactly(): void
    {
        $this->assertSame(1999, Wallet::rupeesToPaise('19.99'));
        $this->assertSame(1999, Wallet::rupeesToPaise(19.99));
        $this->assertSame(10000, Wallet::rupeesToPaise('100'));
        $this->assertSame(10050, Wallet::rupeesToPaise('100.5'));
        $this->assertSame(10005, Wallet::rupeesToPaise('100.05'));
        $this->assertSame(0, Wallet::rupeesToPaise('0.00'));
    }

    public function test_paise_to_rupees_and_format_paise(): void
    {
        $this->assertSame('19.99', Wallet::paiseToRupees(1999));
        $this->assertSame('1000.05', Wallet::paiseToRupees(100005));
        $this->assertSame('₹1,000.05', Wallet::formatPaise(100005));
        $this->assertSame('₹0.00', Wallet::formatPaise(0));
    }

    public function test_rupees_to_paise_rejects_negative_or_three_decimals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Wallet::rupeesToPaise('10.123');
    }
}
