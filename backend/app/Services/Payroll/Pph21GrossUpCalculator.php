<?php

namespace App\Services\Payroll;

use InvalidArgumentException;

class Pph21GrossUpCalculator
{
    private const CATEGORY_BY_PTKP = [
        'TK/0' => 'A', 'TK/1' => 'A', 'K/0' => 'A',
        'TK/2' => 'B', 'TK/3' => 'B', 'K/1' => 'B', 'K/2' => 'B',
        'K/3' => 'C',
    ];

    private const PTKP = [
        'TK/0' => 54000000, 'TK/1' => 58500000, 'TK/2' => 63000000, 'TK/3' => 67500000,
        'K/0' => 58500000, 'K/1' => 63000000, 'K/2' => 67500000, 'K/3' => 72000000,
    ];

    /** Upper bound => rate percentage, from the annex to PP 58/2023. */
    private const TER = [
        'A' => [
            5400000 => 0, 5650000 => .25, 5950000 => .5, 6300000 => .75, 6750000 => 1,
            7500000 => 1.25, 8550000 => 1.5, 9650000 => 1.75, 10050000 => 2,
            10350000 => 2.25, 10700000 => 2.5, 11050000 => 3, 11600000 => 3.5,
            12500000 => 4, 13750000 => 5, 15100000 => 6, 16950000 => 7, 19750000 => 8,
            24150000 => 9, 26450000 => 10, 28000000 => 11, 30050000 => 12,
            32400000 => 13, 35400000 => 14, 39100000 => 15, 43850000 => 16,
            47800000 => 17, 51400000 => 18, 56300000 => 19, 62200000 => 20,
            68600000 => 21, 77500000 => 22, 89000000 => 23, 103000000 => 24,
            125000000 => 25, 157000000 => 26, 206000000 => 27, 337000000 => 28,
            454000000 => 29, 550000000 => 30, 695000000 => 31, 910000000 => 32,
            1400000000 => 33, PHP_INT_MAX => 34,
        ],
        'B' => [
            6200000 => 0, 6500000 => .25, 6850000 => .5, 7300000 => .75, 9200000 => 1,
            10750000 => 1.5, 11250000 => 2, 11600000 => 2.5, 12600000 => 3,
            13600000 => 4, 14950000 => 5, 16400000 => 6, 18450000 => 7,
            21850000 => 8, 26000000 => 9, 27700000 => 10, 29350000 => 11,
            31450000 => 12, 33950000 => 13, 37100000 => 14, 41100000 => 15,
            45800000 => 16, 49500000 => 17, 53800000 => 18, 58500000 => 19,
            64000000 => 20, 71000000 => 21, 80000000 => 22, 93000000 => 23,
            109000000 => 24, 129000000 => 25, 163000000 => 26, 211000000 => 27,
            374000000 => 28, 459000000 => 29, 555000000 => 30, 704000000 => 31,
            957000000 => 32, 1405000000 => 33, PHP_INT_MAX => 34,
        ],
        'C' => [
            6600000 => 0, 6950000 => .25, 7350000 => .5, 7800000 => .75, 8850000 => 1,
            9800000 => 1.25, 10950000 => 1.5, 11200000 => 1.75, 12050000 => 2,
            12950000 => 3, 14150000 => 4, 15550000 => 5, 17050000 => 6,
            19500000 => 7, 22700000 => 8, 26600000 => 9, 28100000 => 10,
            30100000 => 11, 32600000 => 12, 35400000 => 13, 38900000 => 14,
            43000000 => 15, 47400000 => 16, 51200000 => 17, 55800000 => 18,
            60400000 => 19, 66700000 => 20, 74500000 => 21, 83200000 => 22,
            95600000 => 23, 110000000 => 24, 134000000 => 25, 169000000 => 26,
            221000000 => 27, 390000000 => 28, 463000000 => 29, 561000000 => 30,
            709000000 => 31, 965000000 => 32, 1419000000 => 33, PHP_INT_MAX => 34,
        ],
    ];

    public function monthly(float $grossBeforeAllowance, string $ptkpStatus): float
    {
        if ($grossBeforeAllowance <= 0) {
            return 0.0;
        }

        $category = self::CATEGORY_BY_PTKP[$ptkpStatus] ?? throw new InvalidArgumentException('Status PTKP tidak valid: '.$ptkpStatus);
        $lowerBound = 0.0;

        foreach (self::TER[$category] as $upperBound => $ratePercent) {
            $rate = $ratePercent / 100;
            $allowance = $rate === 0.0 ? 0.0 : $grossBeforeAllowance * $rate / (1 - $rate);
            $grossAfterAllowance = $grossBeforeAllowance + $allowance;

            if ($grossAfterAllowance > $lowerBound && $grossAfterAllowance <= $upperBound) {
                // PPh21 uses ordinary whole-rupiah rounding: fractions below .50
                // round down, while .50 and above round up.
                return round($allowance, 0, PHP_ROUND_HALF_UP);
            }

            $lowerBound = (float) $upperBound;
        }

        throw new InvalidArgumentException('Lapisan TER tidak ditemukan.');
    }

    public function finalPeriod(
        float $grossBeforeCurrentAllowance,
        float $priorPph21,
        float $employeePensionContributions,
        int $incomeMonths,
        string $ptkpStatus
    ): float {
        $ptkp = self::PTKP[$ptkpStatus] ?? throw new InvalidArgumentException('Status PTKP tidak valid: '.$ptkpStatus);
        $allowance = 0.0;

        for ($iteration = 0; $iteration < 100; $iteration++) {
            $annualGross = $grossBeforeCurrentAllowance + $allowance;
            $positionExpense = min($annualGross * .05, min(12, max(1, $incomeMonths)) * 500000);
            $annualNet = $annualGross - $positionExpense - $employeePensionContributions;
            $taxableIncome = floor(max(0, $annualNet - $ptkp) / 1000) * 1000;
            $annualTax = $this->article17($taxableIncome);
            $next = round($annualTax - $priorPph21, 2);

            if (abs($next - $allowance) < .01) {
                return round($next, 0, PHP_ROUND_HALF_UP);
            }

            $allowance = $next;
        }

        throw new InvalidArgumentException('Gross-up PPh21 masa pajak terakhir tidak konvergen.');
    }

    public function article17(float $taxableIncome): float
    {
        $remaining = max(0, $taxableIncome);
        $tax = 0.0;
        foreach ([[60000000, .05], [190000000, .15], [250000000, .25], [4500000000, .30], [PHP_INT_MAX, .35]] as [$width, $rate]) {
            $taxable = min($remaining, $width);
            $tax += $taxable * $rate;
            $remaining -= $taxable;
            if ($remaining <= 0) {
                break;
            }
        }

        return round($tax, 2);
    }
}
