<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use Illuminate\Support\Collection;

class AttendanceSummaryService
{
    public function forRecords(Collection $records): array
    {
        $counts = array_fill_keys(array_map(fn ($s) => $s->value, AttendanceStatus::cases()), 0);
        foreach ($records as $record) {
            $counts[$record->status->value]++;
        }

return ['present' => $counts['M'], 'late' => $counts['L'], 'sick' => $counts['S'], 'leave' => $counts['I'], 'vacation' => $counts['C'], 'alpha' => $counts['A'], 'working_days' => $counts['M'] + $counts['L']];
    }
}
