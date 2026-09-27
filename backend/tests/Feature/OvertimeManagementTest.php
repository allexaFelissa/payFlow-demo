<?php

namespace Tests\Feature;

use App\Models\AttendancePeriod;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeePayrollBaseline;
use App\Models\ExtraTime;
use App\Models\Overtime;
use App\Models\OvertimeDetail;
use App\Models\PayrollBaseline;
use App\Models\PayrollSalarySlipSnapshot;
use App\Models\User;
use App\Services\Overtime\ExtraTimeService;
use App\Services\Overtime\OvertimeRateConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OvertimeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_converts_overtime_minutes_to_quarter_hour_rates_after_deducting_one_hour(): void
    {
        $converter = app(OvertimeRateConverter::class);

        $this->assertSame(0.00, $converter->fromDurationMinutes(74));
        $this->assertSame(0.25, $converter->fromDurationMinutes(75));
        $this->assertSame(0.50, $converter->fromDurationMinutes(90));
        $this->assertSame(0.75, $converter->fromDurationMinutes(105));
        $this->assertSame(1.75, $converter->fromDurationMinutes(165));
    }

    public function test_it_loads_present_employees_and_saves_daily_overtime_details(): void
    {
        $this->actingAs(User::factory()->create());
        $employee = Employee::create(['employee_number' => 'EMP-OT-1', 'name' => 'Ayu', 'email' => 'ayu@example.test', 'branch' => 'Jakarta', 'join_date' => '2026-01-01', 'active' => true]);
        Employee::create(['employee_number' => 'EMP-OT-2', 'name' => 'Inactive', 'email' => 'inactive@example.test', 'join_date' => '2026-01-01', 'active' => false]);
        $period = AttendancePeriod::create(['month' => 7, 'year' => 2026, 'status' => 'draft', 'created_by' => User::query()->value('id')]);
        AttendanceRecord::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id, 'attendance_date' => '2026-07-06', 'status' => 'M']);

        $this->getJson('/api/overtime?month=7&year=2026')->assertOk()
            ->assertJsonPath('period.id', $period->id)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', $employee->id);

        $this->getJson('/api/overtime/'.$period->id.'/employees/'.$employee->id)->assertOk()->assertJsonPath('data.0.date', '2026-07-06');
        $this->putJson('/api/overtime/'.$period->id.'/employees/'.$employee->id, ['rows' => [['date' => '2026-07-06', 'starts_at' => '18:00', 'ends_at' => '21:30']]])
            ->assertOk()->assertJsonPath('data.hours', 2.5);

        $this->assertDatabaseCount('overtimes', 1);
        $this->assertDatabaseHas('overtimes', ['employee_id' => $employee->id, 'payroll_period_id' => $period->id, 'hours' => 2.5]);
        $this->assertDatabaseHas('overtime_details', ['duration_minutes' => 210]);
        $this->getJson('/api/overtime/'.$period->id.'/employees/'.$employee->id)
            ->assertOk()
            ->assertJsonPath('data.0.starts_at', '18:00')
            ->assertJsonPath('data.0.ends_at', '21:30')
            ->assertJsonPath('data.0.rate_hours', 2.5);

        $this->putJson('/api/overtime/'.$period->id.'/employees/'.$employee->id, ['rows' => [[
            'date' => '2026-07-06',
            'starts_at' => '08:00 PM',
            'ends_at' => '21:30',
        ]]])->assertUnprocessable()
            ->assertJsonValidationErrors('rows.0.starts_at')
            ->assertJsonPath('errors.rows.0.starts_at.0', 'Jam mulai lembur harus menggunakan format 24 jam HH:mm, contoh 20:00.');

        $this->putJson('/api/overtime/'.$period->id.'/employees/'.$employee->id, ['rows' => [[
            'date' => '2026-07-06',
            'starts_at' => '00:00',
            'ends_at' => '00:00',
        ]]])->assertOk()->assertJsonPath('data.hours', 0);

        $this->assertDatabaseHas('overtime_details', [
            'starts_at' => null,
            'ends_at' => null,
            'duration_minutes' => 0,
        ]);
        $this->getJson('/api/overtime/'.$period->id.'/employees/'.$employee->id)
            ->assertOk()
            ->assertJsonPath('data.0.starts_at', null)
            ->assertJsonPath('data.0.ends_at', null)
            ->assertJsonPath('data.0.duration_label', '0 jam')
            ->assertJsonPath('data.0.rate_hours', 0);
    }

    public function test_payroll_uses_the_dedicated_overtime_amount_once(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $employee = Employee::create(['employee_number' => 'EMP-OT-3', 'name' => 'Budi', 'email' => 'budi@example.test', 'join_date' => '2026-01-01', 'active' => true]);
        $period = AttendancePeriod::create(['month' => 7, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        $period->employeePeriods()->create(['employee_id' => $employee->id, 'monthly_overtime_hours' => 99]);
        $employee->attendanceRecords()->create(['attendance_period_id' => $period->id, 'attendance_date' => '2026-07-01', 'status' => 'M']);
        EmployeePayrollBaseline::create(['employee_id' => $employee->id, 'gaji_pokok' => 5000000, 'tunj_antar_cabang' => 0, 'tunj_komunikasi' => 0, 'tunj_kost' => 0, 'tunj_jabatan' => 0, 'dasar_perhitungan_bpjs_tk' => 5000000, 'dasar_perhitungan_bpjs_kes' => 5000000]);
        PayrollBaseline::create(['jht_pens' => 5.7, 'jkk_jkm' => .54, 'bpjs_p' => 4, 'tunj_bpjs' => 10.24, 'potgn_tk' => 3, 'tunj_prshan' => 10.24, 'potgn_kes' => 1, 'lembur_multiplier' => 1.5, 'insentif_default' => 10, 'is_active' => true]);
        $overtime = Overtime::create(['employee_id' => $employee->id, 'payroll_period_id' => $period->id, 'hours' => 2, 'hourly_rate' => 25000, 'amount' => 50000]);
        $overtime->details()->create(['attendance_date' => '2026-07-01', 'starts_at' => '18:00', 'ends_at' => '20:00', 'duration_minutes' => 120, 'is_holiday' => false]);

        $this->postJson('/api/payroll-calculations/calculate', ['payroll_period_id' => $period->id])->assertOk()
            ->assertJsonPath('data.0.overtime_hours', 1)
            ->assertJsonPath('data.0.overtime_amount', 10000);
    }

    public function test_it_persists_holiday_configuration_for_an_overtime_date(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $employee = Employee::create(['employee_number' => 'EMP-OT-4', 'name' => 'Citra', 'email' => 'citra@example.test', 'join_date' => '2026-01-01', 'active' => true]);
        $period = AttendancePeriod::create(['month' => 7, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        AttendanceRecord::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id, 'attendance_date' => '2026-07-06', 'status' => 'M']);

        $this->putJson('/api/overtime/'.$period->id.'/employees/'.$employee->id, ['rows' => [[
            'date' => '2026-07-06', 'starts_at' => null, 'ends_at' => null, 'is_holiday' => true,
        ]]])->assertOk();

        $this->assertTrue(OvertimeDetail::query()->whereDate('attendance_date', '2026-07-06')->value('is_holiday'));
        $this->getJson('/api/overtime/'.$period->id.'/employees/'.$employee->id)
            ->assertOk()
            ->assertJsonPath('data.0.is_holiday', true);
    }

    public function test_overtime_amount_uses_baseline_rates_for_workdays_and_holidays(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $employee = Employee::create(['employee_number' => 'EMP-OT-RATE', 'name' => 'Dewi', 'branch' => 'DPL', 'active' => true]);
        $period = AttendancePeriod::create(['month' => 7, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        foreach (['2026-07-06', '2026-07-07'] as $date) {
            AttendanceRecord::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id, 'attendance_date' => $date, 'status' => 'M']);
        }
        EmployeePayrollBaseline::create(['employee_id' => $employee->id, 'pt' => 'DPL', 'uang_harian' => 140000]);
        PayrollBaseline::create(['lembur_hari_kerja_rate' => 10000, 'lembur_hari_libur_multiplier' => 2, 'is_active' => true]);

        $this->getJson('/api/overtime/'.$period->id.'/employees/'.$employee->id)
            ->assertOk()
            ->assertJsonPath('rates.workday_rate', 10000)
            ->assertJsonPath('rates.holiday_multiplier', 2)
            ->assertJsonPath('rates.daily_rate', 140000);

        $this->putJson('/api/overtime/'.$period->id.'/employees/'.$employee->id, ['rows' => [
            ['date' => '2026-07-06', 'starts_at' => '18:00', 'ends_at' => '20:00', 'is_holiday' => false],
            ['date' => '2026-07-07', 'starts_at' => null, 'ends_at' => null, 'is_holiday' => true],
        ]])->assertOk()
            ->assertJsonPath('data.hours', 1)
            ->assertJsonPath('data.amount', 10000);

        $this->putJson('/api/overtime/'.$period->id.'/employees/'.$employee->id, ['rows' => [
            ['date' => '2026-07-06', 'starts_at' => '18:00', 'ends_at' => '20:00', 'is_holiday' => false],
            ['date' => '2026-07-07', 'starts_at' => '18:00', 'ends_at' => '20:00', 'is_holiday' => true],
        ]])->assertOk()
            ->assertJsonPath('data.hours', 2)
            ->assertJsonPath('data.hourly_rate', 10000)
            ->assertJsonPath('data.amount', 50000);
    }

    public function test_extra_time_is_only_available_for_coordinator_positions(): void
    {
        $service = app(ExtraTimeService::class);

        foreach (['Koordinator', 'Koordinator Gudang', 'Koordinator Operasional', 'Supervisor Koordinator', 'Coordinator Staff'] as $position) {
            $this->assertTrue($service->eligible(new Employee(['position' => $position])));
        }
        $this->assertFalse($service->eligible(new Employee(['position' => 'Supervisor'])));

        $this->actingAs(User::factory()->create());
        $period = AttendancePeriod::create([
            'month' => 7,
            'year' => 2026,
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-03',
            'status' => 'draft',
            'created_by' => User::query()->value('id'),
        ]);
        $period->dates()->createMany([
            ['attendance_date' => '2026-07-01'],
            ['attendance_date' => '2026-07-02'],
            ['attendance_date' => '2026-07-03'],
        ]);
        $coordinator = Employee::create([
            'employee_number' => 'EMP-ET-1',
            'name' => 'Koordinator Uji',
            'position' => 'Supervisor Coordinator',
            'email' => 'coordinator@example.test',
            'branch' => 'DPL',
            'join_date' => '2026-01-01',
            'active' => true,
        ]);
        $regular = Employee::create([
            'employee_number' => 'EMP-ET-2',
            'name' => 'Karyawan Biasa',
            'position' => 'Supervisor',
            'email' => 'regular@example.test',
            'branch' => 'DPL',
            'join_date' => '2026-01-01',
            'active' => true,
        ]);
        EmployeePayrollBaseline::create([
            'employee_id' => $coordinator->id,
            'gaji_pokok' => 5000000,
            'uang_harian' => 100000,
        ]);
        $coordinator->attendanceRecords()->create([
            'attendance_period_id' => $period->id,
            'attendance_date' => '2026-07-01',
            'status' => 'M',
        ]);

        $this->getJson('/api/overtime?month=7&year=2026')
            ->assertOk()
            ->assertJsonPath('data.0.extra_time_eligible', true);

        $this->getJson('/api/overtime/'.$period->id.'/employees/'.$coordinator->id.'/extra-time')
            ->assertOk()
            ->assertJsonPath('daily_rate', 100000)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.selected', false)
            ->assertJsonPath('data.0.is_holiday', false);

        $rows = [
            ['date' => '2026-07-01', 'selected' => true, 'is_holiday' => false, 'notes' => 'Hari kerja'],
            ['date' => '2026-07-02', 'selected' => true, 'is_holiday' => true, 'notes' => 'Hari libur'],
            ['date' => '2026-07-03', 'selected' => false, 'is_holiday' => false, 'notes' => null],
        ];
        $this->putJson('/api/overtime/'.$period->id.'/employees/'.$coordinator->id.'/extra-time', ['rows' => $rows])
            ->assertOk()
            ->assertJsonPath('total_amount', 225000)
            ->assertJsonPath('data.0.amount', 25000)
            ->assertJsonPath('data.1.amount', 200000);

        $this->assertDatabaseHas('extra_times', [
            'employee_id' => $coordinator->id,
            'payroll_period_id' => $period->id,
            'amount' => 225000,
        ]);
        $this->assertDatabaseCount('extra_time_details', 2);

        $rows[0]['selected'] = false;
        $this->putJson('/api/overtime/'.$period->id.'/employees/'.$coordinator->id.'/extra-time', ['rows' => $rows])
            ->assertOk()
            ->assertJsonPath('total_amount', 200000);
        $this->assertDatabaseCount('extra_time_details', 1);

        $this->getJson('/api/overtime/'.$period->id.'/employees/'.$regular->id.'/extra-time')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_id');
    }

    public function test_payroll_and_salary_slip_include_saved_extra_time_once(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $employee = Employee::create([
            'employee_number' => 'EMP-ET-3',
            'name' => 'Dedi Koordinator',
            'position' => 'Koordinator Operasional',
            'email' => 'dedi@example.test',
            'branch' => 'DPL',
            'join_date' => '2026-01-01',
            'active' => true,
        ]);
        $period = AttendancePeriod::create([
            'month' => 7,
            'year' => 2026,
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-01',
            'status' => 'draft',
            'created_by' => $user->id,
        ]);
        $period->dates()->create(['attendance_date' => '2026-07-01']);
        $period->employeePeriods()->create(['employee_id' => $employee->id]);
        $employee->attendanceRecords()->create([
            'attendance_period_id' => $period->id,
            'attendance_date' => '2026-07-01',
            'status' => 'M',
        ]);
        EmployeePayrollBaseline::create([
            'employee_id' => $employee->id,
            'gaji_pokok' => 5000000,
            'uang_harian' => 100000,
            'dasar_perhitungan_bpjs_tk' => 5000000,
            'dasar_perhitungan_bpjs_kes' => 5000000,
        ]);
        PayrollBaseline::create([
            'jht_pens' => 5.7,
            'jkk_jkm' => .54,
            'bpjs_p' => 4,
            'tunj_bpjs' => 10.24,
            'potgn_tk' => 3,
            'tunj_prshan' => 10.24,
            'potgn_kes' => 1,
            'lembur_multiplier' => 1.5,
            'insentif_default' => 10,
            'is_active' => true,
        ]);

        $this->putJson('/api/overtime/'.$period->id.'/employees/'.$employee->id.'/extra-time', [
            'rows' => [[
                'date' => '2026-07-01',
                'selected' => true,
                'is_holiday' => false,
                'notes' => null,
            ]],
        ])->assertOk()->assertJsonPath('total_amount', 25000);

        $this->postJson('/api/payroll-calculations/calculate', [
            'payroll_period_id' => $period->id,
            'pt' => 'DPL',
        ])->assertOk()
            ->assertJsonPath('data.0.extra_time_amount', 25000)
            ->assertJsonPath('data.0.gross_i', 6137000);

        $this->postJson('/api/payroll-completions/complete', [
            'payroll_period_id' => $period->id,
            'pt' => 'DPL',
        ])->assertOk();

        $snapshot = PayrollSalarySlipSnapshot::query()->firstOrFail();
        $this->assertEquals(25000, $snapshot->payload['income']['extra_time_amount']);
        $html = view('payroll.salary-slip', ['slip' => $snapshot->payload])->render();
        $this->assertStringContainsString('Extra Time', $html);
        $this->assertStringContainsString('Rp 25.000', $html);
        $this->assertEquals(25000, ExtraTime::query()->value('amount'));
    }
}
