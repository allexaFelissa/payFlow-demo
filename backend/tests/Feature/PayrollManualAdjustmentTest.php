<?php

namespace Tests\Feature;

use App\Models\AttendancePeriod;
use App\Models\Employee;
use App\Models\PayrollCalculation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollManualAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_adjustments_change_displayed_totals_without_overwriting_automatic_payroll(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $period = AttendancePeriod::create(['month' => 7, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        $employee = Employee::create(['employee_number' => 'EMP-MANUAL', 'name' => 'Ayu', 'branch' => 'DPL', 'active' => true]);
        $calculation = PayrollCalculation::create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id, 'gross_i' => 1000000, 'gross_ii' => 900000, 'gross_income' => 1000000, 'total_deduction' => 100000, 'take_home_pay' => 900000, 'status' => 'draft']);

        $income = $this->postJson('/api/payroll-calculations/'.$calculation->id.'/manual-adjustments', ['type' => 'income', 'component_name' => 'Bonus Proyek', 'amount' => 200000])
            ->assertOk()
            ->assertJsonPath('data.gross_i', 1200000)
            ->assertJsonPath('data.gross_ii', 1100000)
            ->assertJsonPath('data.total_income', 1200000)
            ->assertJsonPath('data.total_deduction', 100000)
            ->assertJsonPath('data.take_home_pay', 1100000);
        $adjustmentId = $income->json('data.manual_adjustments.0.id');
        $this->assertDatabaseHas('payroll_calculations', ['id' => $calculation->id, 'gross_income' => 1000000, 'total_deduction' => 100000, 'take_home_pay' => 900000]);

        $this->putJson('/api/payroll-calculations/'.$calculation->id.'/manual-adjustments/'.$adjustmentId, ['type' => 'deduction', 'component_name' => 'Administrasi', 'amount' => 50000])
            ->assertOk()
            ->assertJsonPath('data.gross_i', 1000000)
            ->assertJsonPath('data.gross_ii', 900000)
            ->assertJsonPath('data.total_income', 1000000)
            ->assertJsonPath('data.total_deduction', 150000)
            ->assertJsonPath('data.take_home_pay', 850000);
        $this->deleteJson('/api/payroll-calculations/'.$calculation->id.'/manual-adjustments/'.$adjustmentId)
            ->assertOk()->assertJsonPath('data.total_income', 1000000)->assertJsonPath('data.total_deduction', 100000)->assertJsonPath('data.take_home_pay', 900000);
    }

    public function test_manual_adjustments_cannot_be_changed_after_payroll_is_completed(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $period = AttendancePeriod::create(['month' => 7, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        $employee = Employee::create(['employee_number' => 'EMP-CLOSED', 'name' => 'Budi', 'branch' => 'DPL', 'active' => true]);
        $calculation = PayrollCalculation::create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id, 'status' => 'draft']);

        $this->postJson('/api/payroll-completions/complete', ['payroll_period_id' => $period->id, 'pt' => 'DPL'])->assertOk();
        $this->postJson('/api/payroll-calculations/'.$calculation->id.'/manual-adjustments', ['type' => 'income', 'component_name' => 'Bonus', 'amount' => 100000])
            ->assertUnprocessable()->assertJsonValidationErrors('payroll');
    }

    public function test_total_deduction_is_capped_at_twelve_million(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $period = AttendancePeriod::create(['month' => 8, 'year' => 2026, 'status' => 'draft', 'created_by' => $user->id]);
        $employee = Employee::create(['employee_number' => 'EMP-CAP', 'name' => 'Citra', 'branch' => 'DPL', 'active' => true]);
        $calculation = PayrollCalculation::create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id, 'gross_income' => 20000000, 'total_deduction' => 11900000, 'take_home_pay' => 8100000, 'status' => 'draft']);

        $this->postJson('/api/payroll-calculations/'.$calculation->id.'/manual-adjustments', ['type' => 'deduction', 'component_name' => 'Penyesuaian', 'amount' => 500000])
            ->assertOk()->assertJsonPath('data.total_deduction', 12000000)->assertJsonPath('data.take_home_pay', 8000000);
    }
}
