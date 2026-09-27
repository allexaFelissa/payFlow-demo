<?php

namespace App\Enums;

enum AttendancePeriodStatus: string
{
    case Draft = 'draft';
    case Locked = 'locked';
    case Closed = 'closed';
}
