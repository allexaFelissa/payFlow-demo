<?php

namespace App\Services\Overtime;

use App\Models\PayrollBaseline;

class OvertimeAmountCalculator
{
    private const HOLIDAY_WORKING_HOURS = 7;

    public function __construct(private readonly OvertimeRateConverter $rateConverter) {}

    public function calculate(iterable $details, float $dailyRate, PayrollBaseline $configuration): array
    {
        $workdayHours = 0.0;
        $holidayHours = 0.0;

        foreach ($details as $detail) {
            $hours = $this->rateConverter->fromDurationMinutes((int) $detail->duration_minutes);
            if ((bool) $detail->is_holiday) {
                $holidayHours += $hours;
            } else {
                $workdayHours += $hours;
            }
        }

        $workdayAmount = $workdayHours * (float) $configuration->lembur_hari_kerja_rate;
        $holidayAmount = ($holidayHours / self::HOLIDAY_WORKING_HOURS)
            * (float) $configuration->lembur_hari_libur_multiplier
            * $dailyRate;

        return [
            'hours' => round($workdayHours + $holidayHours, 2),
            'workday_hours' => round($workdayHours, 2),
            'holiday_hours' => round($holidayHours, 2),
            'workday_amount' => round($workdayAmount, 2),
            'holiday_amount' => round($holidayAmount, 2),
            'amount' => round($workdayAmount + $holidayAmount, 2),
        ];
    }
}
