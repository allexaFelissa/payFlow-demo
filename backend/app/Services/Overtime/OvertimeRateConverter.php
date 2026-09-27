<?php

namespace App\Services\Overtime;

class OvertimeRateConverter
{
    /**
     * Converts one day's overtime duration to payable decimal hours.
     * One hour is deducted before converting the remaining minutes.
     */
    public function fromDurationMinutes(int $durationMinutes): float
    {
        $payableMinutes = max(0, $durationMinutes - 60);
        $wholeHours = intdiv($payableMinutes, 60);
        $minutes = $payableMinutes % 60;
        $decimalMinutes = match (true) {
            $minutes < 15 => 0.00,
            $minutes < 30 => 0.25,
            $minutes < 45 => 0.50,
            default => 0.75,
        };

        return $wholeHours + $decimalMinutes;
    }
}
