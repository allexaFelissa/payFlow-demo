<?php

namespace Tests\Unit;

use App\Services\Payroll\Pph21GrossUpCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Pph21GrossUpCalculatorTest extends TestCase
{
    #[DataProvider('monthlyCases')]
    public function test_monthly_gross_up_uses_the_pp_58_2023_category_and_rechecks_the_final_bracket(
        float $gross,
        string $status,
        float $expected
    ): void {
        $this->assertEqualsWithDelta($expected, (new Pph21GrossUpCalculator)->monthly($gross, $status), .01);
    }

    public static function monthlyCases(): array
    {
        return [
            'official DJP bracket-crossing example' => [10000000, 'K/0', 230179],
            'category A zero rate' => [5400000, 'TK/0', 0],
            'category A 0.75 percent' => [6000000, 'TK/1', 45340],
            'category B 0.25 percent rounds up' => [6300000, 'K/1', 15789],
            'category C 0.50 percent rounds up' => [7000000, 'K/3', 35176],
            'category B 5 percent rounds up' => [13588700, 'K/2', 715195],
        ];
    }

    public function test_final_period_uses_article_17_less_prior_withholding_and_grosses_up_the_difference(): void
    {
        $calculator = new Pph21GrossUpCalculator;

        $allowance = $calculator->finalPeriod(
            grossBeforeCurrentAllowance: 120000000,
            priorPph21: 2200000,
            employeePensionContributions: 1200000,
            incomeMonths: 12,
            ptkpStatus: 'K/0'
        );

        $this->assertEqualsWithDelta(542100, $allowance, .01);
    }
}
