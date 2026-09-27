<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'M';
    case Late = 'L';
    case Sick = 'S';
    case LeavePermission = 'I';
    case Vacation = 'C';
    case Alpha = 'A';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present', self::Late => 'Late', self::Sick => 'Sick', self::LeavePermission => 'Leave', self::Vacation => 'Vacation', self::Alpha => 'Alpha'
        };
    }
}
