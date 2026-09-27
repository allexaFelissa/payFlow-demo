<?php

namespace Database\Seeders;

use App\Enums\AttendancePeriodStatus;
use App\Models\AttendancePeriod;
use App\Models\AttendancePeriodDate;
use App\Models\AttendancePeriodEmployee;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeePayrollBaseline;
use App\Models\Overtime;
use App\Models\OvertimeDetail;
use App\Models\User;
use App\Services\Payroll\PayrollCalculationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('username', 'admin.hr')->firstOrFail();
        $month = CarbonImmutable::now()->startOfMonth();
        $pts = config('employees.pts');
        $positions = ['Operations Staff', 'Finance Staff', 'HR Staff', 'Warehouse Staff'];

        $employees = collect(range(1, 16))->map(function (int $number) use ($month, $pts, $positions) {
            $pt = $pts[($number - 1) % count($pts)];
            $salary = 4_200_000 + (($number - 1) % 5) * 450_000;

            $employee = Employee::create([
                'employee_number' => sprintf('DEMO-%03d', $number),
                'name' => sprintf('Demo Employee %02d', $number),
                'email' => sprintf('employee%02d@example.invalid', $number),
                'phone' => sprintf('000-0000-%04d', $number),
                'gender' => $number % 2 === 0 ? 'Female' : 'Male',
                'birth_date' => $month->subYears(24 + ($number % 12))->setDay(10),
                'address' => sprintf('Demo Address %02d, Sample City', $number),
                'emergency_contact_name' => sprintf('Demo Contact %02d', $number),
                'emergency_contact_phone' => sprintf('000-9999-%04d', $number),
                'department' => $pt,
                'position' => $positions[($number - 1) % count($positions)],
                'branch' => $pt,
                'pts' => [$pt],
                'family_status' => ['TK/0', 'K/0', 'K/1', 'K/2'][($number - 1) % 4],
                'bank_name' => 'Demo Bank',
                'bank_account_number' => sprintf('DEMO-ACCOUNT-%04d', $number),
                'tax_number' => sprintf('DEMO-TAX-%04d', $number),
                'employment_status' => 'active',
                'join_date' => $month->subYears(1 + ($number % 4))->setDay(1),
                'active' => true,
                'loan_amount' => $number % 5 === 0 ? 2_000_000 : 0,
                'loan_balance' => $number % 5 === 0 ? 1_500_000 : 0,
                'loan_installment' => $number % 5 === 0 ? 500_000 : 0,
                'loan_start_date' => $number % 5 === 0 ? $month->subMonths(2) : null,
                'loan_notes' => $number % 5 === 0 ? 'Fictional demo loan' : null,
            ]);

            EmployeePayrollBaseline::create([
                'employee_id' => $employee->id,
                'pt' => $pt,
                'gaji_pokok' => $salary,
                'uang_harian' => 100_000 + ($number % 3) * 25_000,
                'tunj_antar_cabang' => $number % 4 === 0 ? 350_000 : 0,
                'tunj_komunikasi' => 200_000,
                'tunj_kost' => $number % 3 === 0 ? 500_000 : 0,
                'tunj_jabatan' => $number % 4 === 3 ? 750_000 : 250_000,
                'thr' => 0,
                'dasar_perhitungan_bpjs_tk' => $salary,
                'dasar_perhitungan_bpjs_kes' => $salary,
                'potongan_alpha' => 150_000,
                'incentive_eligible' => true,
            ]);

            return $employee;
        });

        $period = AttendancePeriod::create([
            'month' => $month->month,
            'year' => $month->year,
            'starts_on' => $month->startOfMonth(),
            'ends_on' => $month->endOfMonth(),
            'status' => AttendancePeriodStatus::Locked,
            'created_by' => $admin->id,
            'imported_at' => now(),
        ]);

        $dates = collect();
        for ($date = $month->startOfMonth(); $date->lte($month->endOfMonth()); $date = $date->addDay()) {
            AttendancePeriodDate::create(['attendance_period_id' => $period->id, 'attendance_date' => $date]);
            if ($date->isWeekday()) {
                $dates->push($date);
            }
        }

        foreach ($employees as $index => $employee) {
            AttendancePeriodEmployee::create([
                'attendance_period_id' => $period->id,
                'employee_id' => $employee->id,
                'monthly_overtime_hours' => $index < 6 ? 2.5 + $index : 0,
            ]);

            foreach ($dates as $dayIndex => $date) {
                $status = 'M';
                $remarks = null;
                if ($dayIndex === 3 && $index % 5 === 1) {
                    $status = 'L';
                    $remarks = 'LT30';
                } elseif ($dayIndex === 8 && $index % 7 === 2) {
                    $status = 'S';
                    $remarks = 'SICK_YES';
                } elseif ($dayIndex === 12 && $index % 8 === 3) {
                    $status = 'C';
                    $remarks = 'Demo leave';
                }

                AttendanceRecord::create([
                    'employee_id' => $employee->id,
                    'attendance_period_id' => $period->id,
                    'attendance_date' => $date,
                    'status' => $status,
                    'remarks' => $remarks,
                ]);
            }

            if ($index < 6) {
                $overtime = Overtime::create([
                    'employee_id' => $employee->id,
                    'payroll_period_id' => $period->id,
                    'hours' => 2.5 + $index,
                    'hourly_rate' => 0,
                    'amount' => 0,
                    'notes' => 'Fictional demo overtime',
                ]);
                OvertimeDetail::create([
                    'overtime_id' => $overtime->id,
                    'attendance_date' => $dates[5 + $index],
                    'starts_at' => '17:00',
                    'ends_at' => sprintf('%02d:30', 19 + min($index, 3)),
                    'duration_minutes' => 150 + ($index * 60),
                    'is_holiday' => false,
                ]);
            }
        }

        app(PayrollCalculationService::class)->calculate($period);
    }
}
