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

class PayrollLoanSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_snapshot_can_refresh_but_completed_payroll_never_reads_a_new_master_loan(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $period = AttendancePeriod::create(['month' => 7, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        $employee = Employee::create(['employee_number' => 'EMP-SNAPSHOT', 'name' => 'Ayu', 'branch' => 'DPL', 'active' => true, 'loan_amount' => 500000, 'loan_balance' => 500000, 'loan_installment' => 200000]);
        AttendancePeriodEmployee::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id]);
        AttendanceRecord::create(['attendance_period_id' => $period->id, 'employee_id' => $employee->id, 'attendance_date' => '2026-07-01', 'status' => 'M']);
        EmployeePayrollBaseline::create(['employee_id' => $employee->id, 'gaji_pokok' => 5000000, 'uang_harian' => 0, 'tunj_antar_cabang' => 0, 'tunj_komunikasi' => 0, 'tunj_kost' => 0, 'tunj_jabatan' => 0, 'dasar_perhitungan_bpjs_tk' => 5000000, 'dasar_perhitungan_bpjs_kes' => 5000000]);
        PayrollBaseline::create(['jht_pens' => 5.7, 'jkk_jkm' => .54, 'bpjs_p' => 4, 'tunj_bpjs' => 10.24, 'potgn_tk' => 3, 'tunj_prshan' => 10.24, 'potgn_kes' => 1, 'lembur_multiplier' => 1.5, 'insentif_default' => 10, 'is_active' => true]);

        $this->postJson('/api/payroll-calculations/calculate', ['payroll_period_id' => $period->id, 'pt' => 'DPL'])
            ->assertOk()->assertJsonPath('data.0.potongan_pinjaman', 200000);
        $this->assertDatabaseHas('payroll_loan_details', ['employee_id' => $employee->id, 'installment_amount' => 200000, 'remaining_before' => 500000, 'remaining_after' => 300000]);

        $employee->update(['loan_balance' => 400000, 'loan_installment' => 100000]);
        $this->postJson('/api/payroll-calculations/calculate', ['payroll_period_id' => $period->id, 'pt' => 'DPL'])
            ->assertOk()->assertJsonPath('data.0.potongan_pinjaman', 100000);
        $this->assertDatabaseHas('payroll_loan_details', ['employee_id' => $employee->id, 'installment_amount' => 100000, 'deducted_amount' => 100000, 'remaining_before' => 400000, 'remaining_after' => 300000]);

        $this->postJson('/api/payroll-completions/complete', ['payroll_period_id' => $period->id, 'pt' => 'DPL'])->assertOk();
        $employee->update(['loan_amount' => 900000, 'loan_balance' => 900000, 'loan_installment' => 300000]);

        $this->postJson('/api/payroll-calculations/calculate', ['payroll_period_id' => $period->id, 'pt' => 'DPL'])
            ->assertUnprocessable()->assertJsonValidationErrors('pt');
        $this->getJson('/api/payroll-calculations/'.$period->id)
            ->assertOk()->assertJsonPath('data.0.potongan_pinjaman', 100000)->assertJsonPath('data.0.status', 'completed');
        $this->assertDatabaseHas('payroll_loan_details', ['employee_id' => $employee->id, 'installment_amount' => 100000, 'deducted_amount' => 100000, 'remaining_before' => 400000, 'remaining_after' => 300000]);
    }
}
