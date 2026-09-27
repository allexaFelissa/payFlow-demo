<?php

namespace Tests\Unit;

use App\Models\Employee;
use PHPUnit\Framework\TestCase;

class EmployeePtAssignmentTest extends TestCase
{
    public function test_assigned_pts_follow_employee_page_data(): void
    {
        $employee = new Employee([
            'branch' => 'DPL',
            'pts' => ['TOPI', 'DPL', 'TOPI'],
        ]);

        $this->assertSame(['TOPI', 'DPL'], $employee->assignedPts());
        $this->assertSame('DPL', $employee->primaryPayrollPt());
    }

    public function test_branch_is_used_for_legacy_employee_without_pts(): void
    {
        $employee = new Employee([
            'branch' => 'SRT SBY',
            'pts' => [],
        ]);

        $this->assertSame(['SRT SBY'], $employee->assignedPts());
        $this->assertSame('SRT SBY', $employee->primaryPayrollPt());
    }
}
