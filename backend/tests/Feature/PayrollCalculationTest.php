<?php

namespace Tests\Feature;

use App\Models\AttendancePeriod;
use App\Models\AttendancePeriodEmployee;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeePayrollBaseline;
use App\Models\PayrollBaseline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_and_retrieves_a_payroll_draft_from_baseline_and_attendance_data(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $employee = Employee::create(['employee_number' => 'EMP-200', 'name' => 'Ayu', 'email' => 'ayu@example.test', 'join_date' => '2025-01-01', 'family_status' => 'TK/0', 'active' => true, 'loan_amount' => 500000, 'loan_balance' => 200000, 'loan_installment' => 300000]);
        $period = AttendancePeriod::create(['month' => 6, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        AttendancePeriodEmployee::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id, 'monthly_overtime_hours' => 10]);
        AttendanceRecord::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id, 'attendance_date' => '2026-06-01', 'status' => 'M']);
        AttendanceRecord::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id, 'attendance_date' => '2026-06-02', 'status' => 'C']);
        EmployeePayrollBaseline::create(['employee_id' => $employee->id, 'gaji_pokok' => 5000000, 'tunj_antar_cabang' => 0, 'tunj_komunikasi' => 0, 'tunj_kost' => 0, 'tunj_jabatan' => 0, 'dasar_perhitungan_bpjs_tk' => 5000000, 'dasar_perhitungan_bpjs_kes' => 5000000]);
        PayrollBaseline::create(['jht_pens' => 5.70, 'jkk_jkm' => 0.54, 'bpjs_p' => 4, 'tunj_bpjs' => 10.24, 'potgn_tk' => 3, 'tunj_prshan' => 10.24, 'potgn_kes' => 1, 'lembur_multiplier' => 1.5, 'insentif_default' => 10, 'is_active' => true]);

        $this->postJson('/api/payroll-calculations/calculate', ['payroll_period_id' => $period->id])
            ->assertOk()
            ->assertJsonPath('data.0.employee_id', $employee->id)
            ->assertJsonPath('data.0.hadir', 1)
            ->assertJsonPath('data.0.cuti', 1)
            ->assertJsonPath('data.0.insentif', 500000)
            ->assertJsonPath('data.0.potongan_tk', 150000)
            ->assertJsonPath('data.0.potongan_kes', 50000)
            ->assertJsonPath('data.0.potongan_pinjaman', 200000)
            ->assertJsonPath('data.0.tunj_bpjs_beban_pt', 512000)
            ->assertJsonPath('data.0.tunj_pph21', 29457)
            ->assertJsonPath('data.0.potongan_tunj_pph21', 29457)
            ->assertJsonPath('data.0.gross_i', 6041457.29)
            ->assertJsonPath('data.0.gross_ii', 5891457)
            ->assertJsonPath('data.0.status', 'draft');

        $this->assertDatabaseHas('payroll_calculations', ['payroll_period_id' => $period->id, 'employee_id' => $employee->id, 'gross_income' => 6041457.29, 'total_deduction' => 941457.29, 'take_home_pay' => 5100000]);
        $this->assertDatabaseHas('payroll_loan_details', ['employee_id' => $employee->id, 'loan_amount' => 500000, 'installment_amount' => 300000, 'deducted_amount' => 200000, 'remaining_before' => 200000, 'remaining_after' => 0]);
        $this->getJson('/api/payroll-calculations/'.$period->id)->assertOk()->assertJsonPath('data.0.employee_name', 'Ayu');
    }

    public function test_it_rejects_calculation_when_an_employee_baseline_is_missing(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $employee = Employee::create(['employee_number' => 'EMP-201', 'name' => 'Budi', 'email' => 'budi@example.test', 'join_date' => '2025-01-01', 'active' => true]);
        $period = AttendancePeriod::create(['month' => 6, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        AttendancePeriodEmployee::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id]);
        PayrollBaseline::create(['jht_pens' => 5.70, 'jkk_jkm' => 0.54, 'bpjs_p' => 4, 'tunj_bpjs' => 10.24, 'potgn_tk' => 3, 'tunj_prshan' => 10.24, 'potgn_kes' => 1, 'lembur_multiplier' => 1.5, 'insentif_default' => 10, 'is_active' => true]);

        $this->postJson('/api/payroll-calculations/calculate', ['payroll_period_id' => $period->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payroll_period_id');
    }

    public function test_gross_tax_bases_preserve_the_reference_workbook_precision_without_changing_nett(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $employee = Employee::create(['employee_number' => 'EMP-PRECISION', 'name' => 'Precision Employee', 'branch' => 'DPL', 'pts' => ['DPL'], 'family_status' => 'TK/0', 'active' => true]);
        $period = AttendancePeriod::create(['month' => 7, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        AttendancePeriodEmployee::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id]);
        EmployeePayrollBaseline::create([
            'employee_id' => $employee->id,
            'pt' => 'DPL',
            'gaji_pokok' => 5999443,
            'dasar_perhitungan_bpjs_tk' => 5999443,
            'dasar_perhitungan_bpjs_kes' => 5999443,
        ]);
        PayrollBaseline::create(['jht_pens' => 5.70, 'jkk_jkm' => .54, 'bpjs_p' => 4, 'potgn_tk' => 3, 'potgn_kes' => 1, 'insentif_default' => 0, 'is_active' => true]);

        $this->postJson('/api/payroll-calculations/calculate', ['payroll_period_id' => $period->id, 'pt' => 'DPL'])
            ->assertOk()
            ->assertJsonPath('data.0.tunj_bpjs_beban_pt', 614342.9632)
            ->assertJsonPath('data.0.tunj_pph21', 64987)
            ->assertJsonPath('data.0.gross_i', 6678773.8732)
            ->assertJsonPath('data.0.gross_ii', 6498789.6732)
            ->assertJsonPath('data.0.take_home_pay', 5759465.28);
    }

    public function test_pension_uses_the_wage_cap_while_jht_jkk_and_jkm_use_the_full_bpjs_tk_base(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $employee = Employee::create([
            'employee_number' => 'EMP-BPJS-CAP',
            'name' => 'Demo Example',
            'branch' => 'SRT PM',
            'pts' => ['SRT PM'],
            'family_status' => 'TK/0',
            'active' => true,
        ]);
        $period = AttendancePeriod::create(['month' => 7, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        AttendancePeriodEmployee::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id]);
        EmployeePayrollBaseline::create([
            'employee_id' => $employee->id,
            'pt' => 'SRT PM',
            'gaji_pokok' => 14000000,
            'dasar_perhitungan_bpjs_tk' => 14000000,
            'dasar_perhitungan_bpjs_kes' => 14000000,
        ]);
        PayrollBaseline::create([
            'jht_pens' => 5.70,
            'jkk_jkm' => .54,
            'bpjs_p' => 4,
            'potgn_tk' => 3,
            'potgn_kes' => 1,
            'insentif_default' => 0,
            'is_active' => true,
        ]);

        $this->postJson('/api/payroll-calculations/calculate', [
            'payroll_period_id' => $period->id,
            'pt' => 'SRT PM',
        ])->assertOk()
            ->assertJsonPath('data.0.jht_pens_pt', 739726)
            ->assertJsonPath('data.0.jkk_jkm_pt', 75600)
            ->assertJsonPath('data.0.potongan_tk', 390863);
    }

    public function test_jkk_and_jkm_use_the_pt_specific_risk_rate(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $employee = Employee::create(['employee_number' => 'EMP-TOPI-JKK', 'name' => 'TOPI Risk Rate', 'branch' => 'TOPI', 'pts' => ['TOPI'], 'family_status' => 'TK/0', 'active' => true]);
        $period = AttendancePeriod::create(['month' => 7, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        AttendancePeriodEmployee::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id]);
        EmployeePayrollBaseline::create(['employee_id' => $employee->id, 'pt' => 'TOPI', 'gaji_pokok' => 10250000, 'dasar_perhitungan_bpjs_tk' => 10250000, 'dasar_perhitungan_bpjs_kes' => 10000000]);
        PayrollBaseline::create(['jht_pens' => 5.70, 'jkk_jkm' => .54, 'bpjs_p' => 4, 'potgn_tk' => 3, 'potgn_kes' => 1, 'insentif_default' => 0, 'is_active' => true]);

        $this->postJson('/api/payroll-calculations/calculate', ['payroll_period_id' => $period->id, 'pt' => 'TOPI'])
            ->assertOk()
            ->assertJsonPath('data.0.jkk_jkm_pt', 121975);
    }

    public function test_alpha_deduction_uses_the_daily_employee_baseline(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $employee = Employee::create(['employee_number' => 'EMP-ALPHA', 'name' => 'Alpha Employee', 'branch' => 'DPL', 'pts' => ['DPL'], 'family_status' => 'TK/0', 'active' => true]);
        $period = AttendancePeriod::create(['month' => 8, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        AttendancePeriodEmployee::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id]);
        AttendanceRecord::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id, 'attendance_date' => '2026-08-01', 'status' => 'A']);
        AttendanceRecord::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id, 'attendance_date' => '2026-08-02', 'status' => 'A']);
        AttendanceRecord::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id, 'attendance_date' => '2026-08-03', 'status' => 'L', 'remarks' => 'GT30']);
        EmployeePayrollBaseline::create(['employee_id' => $employee->id, 'pt' => 'DPL', 'gaji_pokok' => 1000000, 'uang_harian' => 100000, 'potongan_alpha' => 100000]);
        PayrollBaseline::create(['jht_pens' => 0, 'jkk_jkm' => 0, 'bpjs_p' => 0, 'potgn_tk' => 0, 'potgn_kes' => 0, 'is_active' => true]);

        $this->postJson('/api/payroll-calculations/calculate', ['payroll_period_id' => $period->id, 'pt' => 'DPL'])
            ->assertOk()
            ->assertJsonPath('data.0.alpha', 2)
            ->assertJsonPath('data.0.potongan_alpha', 200000)
            ->assertJsonPath('data.0.potongan_absensi', 100000)
            ->assertJsonPath('data.0.potongan_terlambat', 100000)
            ->assertJsonPath('data.0.gross_i', 1100000)
            ->assertJsonPath('data.0.gross_ii', 800000)
            ->assertJsonPath('data.0.total_deduction', 300000)
            ->assertJsonPath('data.0.take_home_pay', 800000);

        $this->assertDatabaseHas('payroll_calculations', [
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'alpha' => 2,
            'potongan_alpha' => 200000,
            'potongan_absensi' => 100000,
            'potongan_terlambat' => 100000,
            'total_deduction' => 300000,
        ]);
    }

    public function test_it_calculates_only_the_searched_employee(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $target = Employee::create(['employee_number' => 'EMP-TARGET', 'name' => 'Target', 'email' => 'target@example.test', 'join_date' => '2025-01-01', 'family_status' => 'TK/0', 'active' => true]);
        $other = Employee::create(['employee_number' => 'EMP-OTHER', 'name' => 'Other', 'email' => 'other@example.test', 'join_date' => '2025-01-01', 'family_status' => 'TK/0', 'active' => true]);
        $period = AttendancePeriod::create(['month' => 7, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        foreach ([$target, $other] as $employee) {
            AttendancePeriodEmployee::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id]);
            AttendanceRecord::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id, 'attendance_date' => '2026-07-01', 'status' => 'M']);
        }
        EmployeePayrollBaseline::create(['employee_id' => $target->id, 'gaji_pokok' => 5000000, 'tunj_antar_cabang' => 0, 'tunj_komunikasi' => 0, 'tunj_kost' => 0, 'tunj_jabatan' => 0, 'dasar_perhitungan_bpjs_tk' => 5000000, 'dasar_perhitungan_bpjs_kes' => 5000000]);
        PayrollBaseline::create(['jht_pens' => 5.70, 'jkk_jkm' => .54, 'bpjs_p' => 4, 'tunj_bpjs' => 10.24, 'potgn_tk' => 3, 'tunj_prshan' => 10.24, 'potgn_kes' => 1, 'lembur_multiplier' => 1.5, 'insentif_default' => 10, 'is_active' => true]);
        $this->postJson('/api/payroll-calculations/calculate', ['payroll_period_id' => $period->id, 'search' => 'Target'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_id', $target->id);
        $this->assertDatabaseMissing('payroll_calculations', ['payroll_period_id' => $period->id, 'employee_id' => $other->id]);
    }

    public function test_employee_search_ignores_apostrophes_and_spaces(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $employee = Employee::create(['employee_number' => 'EMP-DEMO-APOSTROPHE', 'name' => "Demo O'Neil", 'branch' => 'TOPI', 'pts' => ['TOPI'], 'family_status' => 'TK/0', 'active' => true]);
        $period = AttendancePeriod::create(['month' => 7, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        EmployeePayrollBaseline::create(['employee_id' => $employee->id, 'pt' => 'TOPI', 'gaji_pokok' => 3600000]);
        PayrollBaseline::create(['jht_pens' => 0, 'jkk_jkm' => 0, 'bpjs_p' => 0, 'potgn_tk' => 0, 'potgn_kes' => 0, 'insentif_default' => 0, 'is_active' => true]);

        $this->postJson('/api/payroll-calculations/calculate', [
            'payroll_period_id' => $period->id,
            'pt' => 'TOPI',
            'search' => 'Demo ONeil',
        ])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', $employee->id);
    }

    public function test_multi_pt_employee_gets_a_separate_calculation_for_each_pt(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $employee = Employee::create([
            'employee_number' => 'EMP-MULTI-PT',
            'name' => 'Karyawan Multi PT',
            'branch' => 'DPL',
            'pts' => ['DPL', 'TOPI'],
            'family_status' => 'TK/0',
            'active' => true,
            'loan_amount' => 500000,
            'loan_balance' => 500000,
            'loan_installment' => 100000,
        ]);
        $period = AttendancePeriod::create(['month' => 8, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        AttendancePeriodEmployee::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id]);
        AttendanceRecord::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id, 'attendance_date' => '2026-08-03', 'status' => 'M']);
        EmployeePayrollBaseline::create(['employee_id' => $employee->id, 'pt' => 'DPL', 'gaji_pokok' => 5000000, 'dasar_perhitungan_bpjs_tk' => 5000000, 'dasar_perhitungan_bpjs_kes' => 5000000]);
        EmployeePayrollBaseline::create(['employee_id' => $employee->id, 'pt' => 'TOPI', 'gaji_pokok' => 7000000, 'dasar_perhitungan_bpjs_tk' => 7000000, 'dasar_perhitungan_bpjs_kes' => 7000000]);
        PayrollBaseline::create(['jht_pens' => 5.70, 'jkk_jkm' => .54, 'bpjs_p' => 4, 'potgn_tk' => 3, 'potgn_kes' => 1, 'is_active' => true]);

        $this->postJson('/api/payroll-calculations/calculate', ['payroll_period_id' => $period->id])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.pt', 'DPL')
            ->assertJsonPath('data.0.gaji_pokok', 5000000)
            ->assertJsonPath('data.1.pt', 'TOPI')
            ->assertJsonPath('data.1.gaji_pokok', 7000000);

        $this->assertDatabaseHas('payroll_calculations', ['payroll_period_id' => $period->id, 'employee_id' => $employee->id, 'pt' => 'DPL', 'potongan_pinjaman' => 100000]);
        $this->assertDatabaseHas('payroll_calculations', ['payroll_period_id' => $period->id, 'employee_id' => $employee->id, 'pt' => 'TOPI', 'potongan_pinjaman' => 0]);

        $this->postJson('/api/payroll-completions/complete', ['payroll_period_id' => $period->id, 'pt' => 'DPL'])->assertOk();
        $this->assertDatabaseHas('payroll_calculations', ['employee_id' => $employee->id, 'pt' => 'DPL', 'status' => 'completed']);
        $this->assertDatabaseHas('payroll_calculations', ['employee_id' => $employee->id, 'pt' => 'TOPI', 'status' => 'draft']);

        $this->postJson('/api/payroll-completions/complete', ['payroll_period_id' => $period->id, 'pt' => 'TOPI'])->assertOk();
        $this->assertDatabaseHas('payroll_salary_slip_snapshots', ['attendance_period_id' => $period->id, 'employee_number' => 'EMP-MULTI-PT', 'pt' => 'DPL']);
        $this->assertDatabaseHas('payroll_salary_slip_snapshots', ['attendance_period_id' => $period->id, 'employee_number' => 'EMP-MULTI-PT', 'pt' => 'TOPI']);
    }

    public function test_active_employee_without_attendance_is_still_calculated_with_fixed_components(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $employee = Employee::create([
            'employee_number' => 'EMP-NO-ATTENDANCE',
            'name' => 'Tanpa Absensi',
            'branch' => 'DPL',
            'pts' => ['DPL'],
            'family_status' => 'TK/0',
            'active' => true,
        ]);
        $period = AttendancePeriod::create(['month' => 9, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        EmployeePayrollBaseline::create([
            'employee_id' => $employee->id,
            'gaji_pokok' => 5000000,
            'uang_harian' => 100000,
            'tunj_komunikasi' => 200000,
            'tunj_jabatan' => 300000,
            'dasar_perhitungan_bpjs_tk' => 5000000,
            'dasar_perhitungan_bpjs_kes' => 5000000,
        ]);
        PayrollBaseline::create(['jht_pens' => 5.70, 'jkk_jkm' => .54, 'bpjs_p' => 4, 'potgn_tk' => 3, 'potgn_kes' => 1, 'insentif_default' => 10, 'is_active' => true]);

        $this->postJson('/api/payroll-calculations/calculate', [
            'payroll_period_id' => $period->id,
            'pt' => 'DPL',
        ])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', $employee->id)
            ->assertJsonPath('data.0.gaji_pokok', 5000000)
            ->assertJsonPath('data.0.tunj_komunikasi', 200000)
            ->assertJsonPath('data.0.tunj_jabatan', 300000)
            ->assertJsonPath('data.0.uang_harian', 0)
            ->assertJsonPath('data.0.insentif', 0)
            ->assertJsonPath('data.0.hadir', 0)
            ->assertJsonPath('data.0.izin', 0)
            ->assertJsonPath('data.0.sakit', 0)
            ->assertJsonPath('data.0.cuti', 0)
            ->assertJsonPath('data.0.alpha', 0);
    }

    public function test_employee_incentive_eligibility_can_be_disabled_per_baseline(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $employee = Employee::create(['employee_number' => 'EMP-NO-INCENTIVE', 'name' => 'No Incentive', 'branch' => 'SRT CKRG', 'pts' => ['SRT CKRG'], 'family_status' => 'K/2', 'active' => true]);
        $period = AttendancePeriod::create(['month' => 7, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        AttendancePeriodEmployee::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id]);
        AttendanceRecord::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id, 'attendance_date' => '2026-07-01', 'status' => 'M']);
        EmployeePayrollBaseline::create(['employee_id' => $employee->id, 'pt' => 'SRT CKRG', 'gaji_pokok' => 6750000, 'dasar_perhitungan_bpjs_tk' => 6750000, 'dasar_perhitungan_bpjs_kes' => 6500000, 'incentive_eligible' => false]);
        PayrollBaseline::create(['jht_pens' => 5.70, 'jkk_jkm' => .54, 'bpjs_p' => 4, 'potgn_tk' => 3, 'potgn_kes' => 1, 'insentif_default' => 10, 'is_active' => true]);

        $this->postJson('/api/payroll-calculations/calculate', ['payroll_period_id' => $period->id, 'pt' => 'SRT CKRG'])
            ->assertOk()
            ->assertJsonPath('data.0.insentif', 0);
    }
}
