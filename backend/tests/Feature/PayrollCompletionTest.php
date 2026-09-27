<?php

namespace Tests\Feature;

use App\Models\AttendancePeriod;
use App\Models\Employee;
use App\Models\PayrollCalculation;
use App\Models\PayrollCompletion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_completion_is_per_pt_and_undo_restores_the_loan(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $period = AttendancePeriod::create(['month' => 6, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        $dplEmployee = Employee::create(['employee_number' => 'EMP-DPL', 'name' => 'Ayu', 'email' => 'ayu@example.test', 'branch' => 'DPL', 'join_date' => '2026-01-01', 'active' => true, 'loan_balance' => 500000, 'loan_installment' => 300000, 'loan_notes' => 'Pinjaman karyawan']);
        $topiEmployee = Employee::create(['employee_number' => 'EMP-TOPI', 'name' => 'Budi', 'email' => 'budi@example.test', 'branch' => 'TOPI', 'join_date' => '2026-01-01', 'active' => true, 'loan_balance' => 400000, 'loan_installment' => 200000]);
        $dplCalculation = PayrollCalculation::create(['payroll_period_id' => $period->id, 'employee_id' => $dplEmployee->id, 'potongan_pinjaman' => 300000, 'total_deduction' => 300000]);
        $dplCalculation->loanDetail()->create(['employee_id' => $dplEmployee->id, 'loan_amount' => 500000, 'installment_amount' => 300000, 'deducted_amount' => 300000, 'remaining_before' => 500000, 'remaining_after' => 200000, 'loan_notes' => 'Pinjaman karyawan']);
        $topiCalculation = PayrollCalculation::create(['payroll_period_id' => $period->id, 'employee_id' => $topiEmployee->id, 'potongan_pinjaman' => 200000, 'total_deduction' => 200000]);
        $topiCalculation->loanDetail()->create(['employee_id' => $topiEmployee->id, 'loan_amount' => 400000, 'installment_amount' => 200000, 'deducted_amount' => 200000, 'remaining_before' => 400000, 'remaining_after' => 200000]);

        $this->postJson('/api/payroll-completions/complete', ['payroll_period_id' => $period->id, 'pt' => 'DPL'])->assertOk()->assertJsonPath('completed', true);
        $this->assertDatabaseHas('employees', ['id' => $dplEmployee->id, 'loan_balance' => 200000]);
        $this->assertDatabaseHas('payroll_calculations', ['payroll_period_id' => $period->id, 'employee_id' => $dplEmployee->id, 'status' => 'completed']);
        $this->assertDatabaseHas('employees', ['id' => $topiEmployee->id, 'loan_balance' => 400000]);
        $this->getJson('/api/payroll-completions/'.$period->id.'?pt=DPL')->assertOk()->assertJsonPath('completed', true);
        $this->getJson('/api/payroll-completions/'.$period->id.'?pt=TOPI')->assertOk()->assertJsonPath('completed', false);

        $this->postJson('/api/payroll-completions/undo', ['payroll_period_id' => $period->id, 'pt' => 'DPL'])->assertOk()->assertJsonPath('completed', false);
        $this->assertDatabaseHas('employees', ['id' => $dplEmployee->id, 'loan_balance' => 500000, 'loan_notes' => 'Pinjaman karyawan']);
        $this->assertDatabaseHas('payroll_calculations', ['payroll_period_id' => $period->id, 'employee_id' => $dplEmployee->id, 'status' => 'draft']);
        $this->assertDatabaseMissing('payroll_completions', ['attendance_period_id' => $period->id, 'pt' => 'DPL']);
    }

    public function test_final_loan_installment_clears_master_data_and_undo_restores_the_loan(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $period = AttendancePeriod::create(['month' => 7, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        $employee = Employee::create(['employee_number' => 'EMP-FINAL-LOAN', 'name' => 'Citra', 'branch' => 'DPL', 'active' => true, 'loan_amount' => 500000, 'loan_balance' => 200000, 'loan_installment' => 300000, 'loan_start_date' => '2026-01-01', 'loan_notes' => 'Pelunasan terakhir']);
        $calculation = PayrollCalculation::create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id, 'potongan_pinjaman' => 200000, 'total_deduction' => 200000]);
        $calculation->loanDetail()->create(['employee_id' => $employee->id, 'loan_amount' => 500000, 'installment_amount' => 300000, 'deducted_amount' => 200000, 'remaining_before' => 200000, 'remaining_after' => 0, 'loan_start_date' => '2026-01-01', 'loan_notes' => 'Pelunasan terakhir']);

        $this->postJson('/api/payroll-completions/complete', ['payroll_period_id' => $period->id, 'pt' => 'DPL'])->assertOk();
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'loan_amount' => 0,
            'loan_balance' => 0,
            'loan_installment' => 0,
            'loan_start_date' => null,
            'loan_notes' => null,
        ]);
        $this->getJson('/api/employees/'.$employee->id.'/loan-history')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.period_month', 7)
            ->assertJsonPath('data.0.installment_amount', 200000)
            ->assertJsonPath('data.0.remaining_after', 0)
            ->assertJsonPath('data.0.payroll_status', 'Selesai');
        $this->postJson('/api/payroll-completions/complete', ['payroll_period_id' => $period->id, 'pt' => 'DPL'])->assertOk();
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'loan_amount' => 0, 'loan_balance' => 0, 'loan_installment' => 0]);
        $this->getJson('/api/employees/'.$employee->id.'/loan-history')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson('/api/payroll-completions/undo', ['payroll_period_id' => $period->id, 'pt' => 'DPL'])->assertOk();
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'loan_amount' => 500000, 'loan_balance' => 200000, 'loan_installment' => 300000, 'loan_notes' => 'Pelunasan terakhir']);
        $this->assertSame('2026-01-01', $employee->refresh()->loan_start_date?->toDateString());
        $this->getJson('/api/employees/'.$employee->id.'/loan-history')->assertOk()->assertJsonCount(0, 'data');

        $this->postJson('/api/payroll-completions/complete', ['payroll_period_id' => $period->id, 'pt' => 'DPL'])->assertOk();
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'loan_balance' => 0]);
        $this->getJson('/api/employees/'.$employee->id.'/loan-history')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/payroll-completions/undo', ['payroll_period_id' => $period->id, 'pt' => 'DPL'])->assertOk();
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'loan_balance' => 200000]);
    }

    public function test_loan_history_is_sorted_by_the_latest_payroll_period(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $employee = Employee::create(['employee_number' => 'EMP-RIWAYAT', 'name' => 'Dewi', 'branch' => 'DPL', 'active' => true]);
        $june = AttendancePeriod::create(['month' => 6, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        $july = AttendancePeriod::create(['month' => 7, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);

        $julyCompletion = PayrollCompletion::create(['attendance_period_id' => $july->id, 'pt' => 'DPL', 'completed_by' => $user->id]);
        $julyCompletion->loanFinalizations()->create([
            'employee_id' => $employee->id, 'deduction_amount' => 50000, 'loan_balance_before' => 450000,
        ]);
        $juneCompletion = PayrollCompletion::create(['attendance_period_id' => $june->id, 'pt' => 'DPL', 'completed_by' => $user->id]);
        $juneCompletion->loanFinalizations()->create([
            'employee_id' => $employee->id, 'deduction_amount' => 100000, 'loan_balance_before' => 600000,
        ]);

        $this->getJson('/api/employees/'.$employee->id.'/loan-history')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.period_month', 7)
            ->assertJsonPath('data.0.remaining_after', 400000)
            ->assertJsonPath('data.1.period_month', 6)
            ->assertJsonPath('data.1.remaining_after', 500000);
    }
}
