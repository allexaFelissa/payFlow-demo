<?php

namespace App\Policies;

use App\Models\User;

class AttendancePeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function manage(User $user): bool
    {
        return true;
    }
}
