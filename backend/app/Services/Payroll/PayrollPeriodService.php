<?php

namespace App\Services\Payroll;

use App\Models\AttendancePeriod;
use Carbon\Carbon;

class PayrollPeriodService
{
    public const LABELS = [1 => 'Jan–Feb', 2 => 'Feb–Mar', 3 => 'Mar–Apr', 4 => 'Apr–Mei', 5 => 'Mei–Jun', 6 => 'Jun–Jul', 7 => 'Jul–Agu', 8 => 'Agu–Sep', 9 => 'Sep–Okt', 10 => 'Okt–Nov', 11 => 'Nov–Des', 12 => 'Des–Jan'];

    public function dates(int $year, int $period): array
    {
        $start = Carbon::create($year, $period, 22)->startOfDay();

        return ['start' => $start, 'end' => $start->copy()->addMonth()->day(21)->endOfDay(), 'label' => self::LABELS[$period]];
    }

    public function attendancePeriod(int $year, int $period): ?AttendancePeriod
    {
        $dates = $this->dates($year, $period);

        return AttendancePeriod::query()->whereDate('starts_on', $dates['start'])->whereDate('ends_on', $dates['end'])->first();
    }
}
