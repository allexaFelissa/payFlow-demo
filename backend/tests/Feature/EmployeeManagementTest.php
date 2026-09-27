<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_can_create_an_employee_with_only_the_requested_information(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->postJson('/api/employees', [
            'employee_number' => 'EMP-000',
            'name' => 'Dewi',
            'department' => 'SRT SBY',
            'active' => true,
            'loan_amount' => 5000000,
            'loan_installment' => 500000,
        ])->assertCreated()->assertJsonPath('data.name', 'Dewi');

        $this->assertDatabaseHas('employees', ['employee_number' => 'EMP-000', 'email' => null, 'join_date' => null]);
    }

    public function test_a_new_loan_uses_its_initial_amount_as_the_balance_and_requires_an_installment(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $payload = ['employee_number' => 'EMP-LOAN', 'name' => 'Loan Employee', 'department' => 'SRT SBY', 'active' => true, 'loan_amount' => 5000000, 'loan_installment' => 500000];
        $this->postJson('/api/employees', $payload)->assertCreated()->assertJsonPath('data.loan_balance', 5000000);
        $this->assertDatabaseHas('employees', ['employee_number' => 'EMP-LOAN', 'loan_amount' => 5000000, 'loan_balance' => 5000000, 'loan_installment' => 500000]);

        $this->postJson('/api/employees', [...$payload, 'employee_number' => 'EMP-LOAN-NO-INSTALLMENT', 'loan_installment' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('loan_installment');
        $this->postJson('/api/employees', [...$payload, 'employee_number' => 'EMP-LOAN-OVER-INSTALLMENT', 'loan_installment' => 5000000.01])
            ->assertUnprocessable()->assertJsonValidationErrors('loan_installment');
        $this->postJson('/api/employees', [...$payload, 'employee_number' => 'EMP-LOAN-MANUAL-BALANCE', 'loan_balance' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('loan_balance');
    }

    public function test_employees_can_be_searched_and_filtered_by_division(): void
    {
        $this->actingAs(User::factory()->create());
        Employee::create(['employee_number' => 'EMP-001', 'name' => 'Ayu DPL', 'email' => 'ayu@example.test', 'branch' => 'DPL', 'join_date' => '2025-01-01']);
        Employee::create(['employee_number' => 'EMP-002', 'name' => 'Budi TOPI', 'email' => 'budi@example.test', 'branch' => 'TOPI', 'join_date' => '2025-01-01']);

        $this->getJson('/api/employees?department=DPL')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Ayu DPL');
        $this->getJson('/api/employees?search=TOPI')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Budi TOPI');
        $this->getJson('/api/employees/divisions')
            ->assertOk()->assertJsonFragment(['name' => 'DPL', 'employee_count' => 1]);
    }

    public function test_hr_can_update_employee_profile_and_loan_information(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $employee = Employee::create(['employee_number' => 'EMP-003', 'name' => 'Citra', 'email' => 'citra@example.test', 'join_date' => '2025-01-01']);

        $this->putJson('/api/employees/'.$employee->id, [
            'employee_number' => 'EMP-003', 'name' => 'Citra', 'email' => 'citra@example.test',
            'department' => 'DPL', 'active' => true,
            'bank_name' => 'BCA', 'bank_account_number' => '123456789', 'tax_number' => 'NPWP-01',
            'loan_amount' => 12000000, 'loan_installment' => 1000000,
            'loan_start_date' => '2026-07-01', 'loan_notes' => 'Employee cash loan',
        ])->assertOk()->assertJsonPath('data.loan_balance', 12000000)->assertJsonPath('data.department', 'DPL');

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id, 'loan_amount' => 12000000, 'loan_balance' => 12000000,
            'loan_installment' => 1000000, 'bank_name' => 'BCA',
        ]);

        $this->putJson('/api/employees/'.$employee->id, [
            'employee_number' => 'EMP-003', 'name' => 'Citra', 'email' => 'citra@example.test',
            'department' => 'DPL', 'active' => true, 'loan_amount' => 11000000,
            'loan_installment' => 1000000,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('loan_amount')
            ->assertJsonPath('errors.loan_amount.0', 'Jumlah Pinjaman Awal tidak dapat diubah setelah pinjaman dibuat.');
    }

    public function test_a_paid_loan_can_be_replaced_with_a_new_loan(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $employee = Employee::create([
            'employee_number' => 'EMP-NEW-LOAN',
            'name' => 'Dewi',
            'branch' => 'DPL',
            'active' => true,
            'loan_amount' => 500000,
            'loan_balance' => 0,
            'loan_installment' => 100000,
            'loan_notes' => 'Pinjaman lama sudah lunas',
        ]);

        $this->putJson('/api/employees/'.$employee->id, [
            'employee_number' => 'EMP-NEW-LOAN',
            'name' => 'Dewi',
            'department' => 'DPL',
            'active' => true,
            'loan_amount' => 700000,
            'loan_installment' => 70000,
            'loan_start_date' => '2026-07-24',
            'loan_notes' => 'Pinjaman baru',
        ])->assertOk()
            ->assertJsonPath('data.loan_amount', 700000)
            ->assertJsonPath('data.loan_balance', 700000)
            ->assertJsonPath('data.loan_installment', 70000);

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'loan_amount' => 700000,
            'loan_balance' => 700000,
            'loan_installment' => 70000,
            'loan_notes' => 'Pinjaman baru',
        ]);
    }

    public function test_admin_can_clear_an_active_loan_back_to_its_defaults(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $employee = Employee::create([
            'employee_number' => 'EMP-CLEAR-LOAN',
            'name' => 'Loan To Clear',
            'branch' => 'DPL',
            'active' => true,
            'loan_amount' => 500000,
            'loan_balance' => 400000,
            'loan_installment' => 100000,
            'loan_start_date' => '2026-07-01',
            'loan_notes' => 'Pinjaman dibatalkan',
        ]);

        $this->postJson('/api/employees/'.$employee->id.'/loan/clear')
            ->assertOk()
            ->assertJsonPath('data.loan_amount', 0)
            ->assertJsonPath('data.loan_balance', 0)
            ->assertJsonPath('data.loan_installment', 0)
            ->assertJsonPath('data.loan_start_date', null)
            ->assertJsonPath('data.loan_notes', null);

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'loan_amount' => 0,
            'loan_balance' => 0,
            'loan_installment' => 0,
            'loan_start_date' => null,
            'loan_notes' => null,
        ]);
    }

    public function test_staff_cannot_clear_an_employee_loan(): void
    {
        $this->actingAs(User::factory()->create());
        $employee = Employee::create([
            'employee_number' => 'EMP-STAFF-CLEAR-LOAN',
            'name' => 'Protected Loan',
            'branch' => 'DPL',
            'active' => true,
            'loan_amount' => 500000,
            'loan_balance' => 500000,
            'loan_installment' => 100000,
        ]);

        $this->postJson('/api/employees/'.$employee->id.'/loan/clear')->assertForbidden();

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'loan_amount' => 500000,
            'loan_balance' => 500000,
        ]);
    }

    public function test_admin_can_edit_employee_name_number_and_assign_multiple_pts(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $employee = Employee::create([
            'employee_number' => 'EMP-OLD',
            'name' => 'Nama Lama',
            'branch' => 'DPL',
            'pts' => ['DPL'],
            'active' => true,
        ]);

        $this->putJson('/api/employees/'.$employee->id, [
            'employee_number' => 'EMP-NEW',
            'name' => 'Nama Baru',
            'pts' => ['DPL', 'TOPI'],
            'branch' => 'DPL',
            'active' => true,
        ])->assertOk()
            ->assertJsonPath('data.employee_number', 'EMP-NEW')
            ->assertJsonPath('data.name', 'Nama Baru')
            ->assertJsonPath('data.pts', ['DPL', 'TOPI']);

        $this->getJson('/api/employees?branch=TOPI')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $employee->id);
    }

    public function test_staff_cannot_edit_employee_name_or_number(): void
    {
        $this->actingAs(User::factory()->create());
        $employee = Employee::create([
            'employee_number' => 'EMP-LOCKED',
            'name' => 'Nama Tetap',
            'branch' => 'DPL',
            'pts' => ['DPL'],
            'active' => true,
        ]);

        $this->putJson('/api/employees/'.$employee->id, [
            'employee_number' => 'EMP-CHANGED',
            'name' => 'Nama Diubah',
            'active' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['employee_number', 'name']);

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'employee_number' => 'EMP-LOCKED',
            'name' => 'Nama Tetap',
        ]);
    }

    public function test_admin_gets_a_clear_message_when_employee_number_is_already_used(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Employee::create([
            'employee_number' => '601032',
            'name' => 'Pemilik ID',
            'branch' => 'DPL',
            'pts' => ['DPL'],
            'active' => true,
        ]);
        $employee = Employee::create([
            'employee_number' => '601033',
            'name' => 'Karyawan Lain',
            'branch' => 'DPL',
            'pts' => ['DPL'],
            'active' => true,
        ]);

        $this->putJson('/api/employees/'.$employee->id, [
            'employee_number' => '601032',
            'name' => 'Karyawan Lain',
            'pts' => ['DPL'],
            'branch' => 'DPL',
            'active' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.employee_number.0', 'ID karyawan sudah digunakan oleh karyawan lain. Gunakan ID yang berbeda.');
    }

    public function test_a_new_employee_cannot_reuse_an_existing_id_or_exact_name(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Employee::create([
            'employee_number' => '601032',
            'name' => 'Demo Duplicate Employee',
            'branch' => 'DPL',
            'pts' => ['DPL'],
            'active' => true,
        ]);

        $this->postJson('/api/employees', [
            'employee_number' => '601032',
            'name' => 'Demo Duplicate Employee',
            'pts' => ['TOPI'],
            'active' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.employee_number.0', 'ID karyawan sudah digunakan oleh karyawan lain. Gunakan ID yang berbeda.')
            ->assertJsonPath('errors.name.0', 'Nama karyawan sudah terdaftar. Gunakan nama yang berbeda.');
    }
}
