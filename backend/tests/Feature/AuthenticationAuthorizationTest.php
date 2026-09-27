<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_only_login_with_a_valid_username_and_password(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'admin.hr', 'password' => 'Secret123!']);

        $this->assertTrue(Hash::check('Secret123!', $admin->password));
        $this->postJson('/api/login', ['username' => 'admin.hr', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/login', ['username' => 'admin.hr', 'password' => 'Secret123!'])
            ->assertOk()->assertJsonPath('user.role', 'admin')->assertJsonStructure(['token', 'user' => ['username', 'permissions']]);
    }

    public function test_api_requires_authentication(): void
    {
        $this->getJson('/api/employees')->assertUnauthorized();
        $this->getJson('/api/attendance')->assertUnauthorized();
        $this->getJson('/api/payroll-baselines/employees')->assertUnauthorized();
    }

    public function test_staff_cannot_access_payroll_or_loan_information(): void
    {
        $staff = User::factory()->create();
        $employee = Employee::create([
            'employee_number' => 'EMP-SEC', 'name' => 'Secure Employee', 'department' => 'DPL',
            'active' => true, 'loan_amount' => 10000000, 'loan_balance' => 8000000, 'loan_installment' => 1000000,
        ]);
        $this->actingAs($staff);

        $this->getJson('/api/payroll-baselines/employees')->assertForbidden();
        $this->postJson('/api/payroll-calculations/calculate', [])->assertForbidden();
        $this->getJson('/api/employees/'.$employee->id)->assertOk()
            ->assertJsonMissingPath('data.loan_amount')->assertJsonMissingPath('data.loan_balance');
        $this->putJson('/api/employees/'.$employee->id, [
            'employee_number' => 'EMP-SEC', 'name' => 'Secure Employee', 'department' => 'DPL',
            'active' => true, 'loan_balance' => 0,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'loan_balance' => 8000000]);
    }

    public function test_admin_can_access_payroll_and_loan_information(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = Employee::create(['employee_number' => 'EMP-ADMIN', 'name' => 'Admin Employee', 'department' => 'TOPI', 'active' => true, 'loan_amount' => 5000000]);
        $this->actingAs($admin);

        $this->getJson('/api/payroll-baselines/employees')->assertOk();
        $this->getJson('/api/employees/'.$employee->id)->assertOk()->assertJsonPath('data.loan_amount', 5000000);
    }
}
