<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollBaseline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollBaselineTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_saves_an_employee_payroll_baseline(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $employee = Employee::create(['employee_number' => 'EMP-100', 'name' => 'Ayu', 'email' => 'ayu@example.test', 'join_date' => '2025-01-01', 'branch' => 'SRT SBY', 'pts' => ['SRT SBY'], 'active' => true]);

        $payload = ['gaji_pokok' => 5000000, 'uang_harian' => 150000, 'tunj_antar_cabang' => 100000, 'tunj_komunikasi' => 200000, 'tunj_kost' => 300000, 'tunj_jabatan' => 400000, 'thr' => 500000, 'dasar_perhitungan_bpjs_tk' => 5000000, 'dasar_perhitungan_bpjs_kes' => 5000000, 'potongan_alpha' => 100000, 'pt' => 'SRT SBY', 'title' => 'HR Manager', 'family_status' => 'K/2'];
        $this->putJson('/api/payroll-baselines/employees/'.$employee->id, $payload)->assertOk()->assertJsonPath('data.employee_name', 'Ayu')->assertJsonPath('data.pt', 'SRT SBY')->assertJsonPath('data.title', 'HR Manager')->assertJsonPath('data.family_status', 'K/2')->assertJsonPath('data.gaji_pokok', 5000000)->assertJsonPath('data.uang_harian', 150000)->assertJsonMissingPath('data.tunj_pph21')->assertJsonPath('data.thr', 500000)->assertJsonPath('data.potongan_alpha', 100000);

        $this->assertDatabaseHas('master_data_komponen_gaji', ['employee_id' => $employee->id, 'pt' => 'SRT SBY', 'gaji_pokok' => 5000000, 'uang_harian' => 150000, 'tunj_jabatan' => 400000, 'thr' => 500000, 'potongan_alpha' => 100000]);
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'branch' => 'SRT SBY']);
        $this->getJson('/api/employees?branch=SRT%20SBY')->assertOk()->assertJsonPath('data.0.branch', 'SRT SBY')->assertJsonPath('data.0.department', 'SRT SBY');
        $this->getJson('/api/payroll-baselines/employees?search=Ayu')->assertOk()->assertJsonPath('data.0.employee_id', $employee->id);
    }

    public function test_it_rejects_bpjs_contribution_totals_entered_as_wage_bases(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $employee = Employee::create([
            'employee_number' => 'EMP-BPJS-BASE',
            'name' => 'BPJS Base Check',
            'branch' => 'SRT CKRG',
            'pts' => ['SRT CKRG'],
            'active' => true,
        ]);
        PayrollBaseline::create([
            'jht_pens' => 5.70,
            'jkk_jkm' => .54,
            'bpjs_p' => 4,
            'potgn_tk' => 3,
            'potgn_kes' => 1,
            'is_active' => true,
        ]);

        $this->putJson('/api/payroll-baselines/employees/'.$employee->id, [
            'pt' => 'SRT CKRG',
            'gaji_pokok' => 3750000,
            'uang_harian' => 70000,
            'tunj_antar_cabang' => 0,
            'tunj_komunikasi' => 0,
            'tunj_kost' => 0,
            'tunj_jabatan' => 0,
            'thr' => 0,
            'dasar_perhitungan_bpjs_tk' => 548753,
            'dasar_perhitungan_bpjs_kes' => 296944,
            'potongan_alpha' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'dasar_perhitungan_bpjs_tk',
                'dasar_perhitungan_bpjs_kes',
            ]);

        $this->assertDatabaseMissing('master_data_komponen_gaji', [
            'employee_id' => $employee->id,
        ]);
    }

    public function test_multi_pt_employee_has_an_independent_baseline_row_for_each_employee_pt(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $employee = Employee::create([
            'employee_number' => 'EMP-MULTI-BASELINE',
            'name' => 'Karyawan Multi PT',
            'branch' => 'DPL',
            'pts' => ['DPL', 'TOPI'],
            'active' => true,
        ]);
        $payload = [
            'uang_harian' => 100000,
            'tunj_antar_cabang' => 0,
            'tunj_komunikasi' => 0,
            'tunj_kost' => 0,
            'tunj_jabatan' => 0,
            'thr' => 0,
            'dasar_perhitungan_bpjs_tk' => 5000000,
            'dasar_perhitungan_bpjs_kes' => 5000000,
            'potongan_alpha' => 50000,
            'title' => null,
            'family_status' => null,
        ];

        $this->getJson('/api/payroll-baselines/employees?search=EMP-MULTI-BASELINE')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.employee_number', 'EMP-MULTI-BASELINE')
            ->assertJsonPath('data.0.pt', 'DPL')
            ->assertJsonPath('data.1.employee_number', 'EMP-MULTI-BASELINE')
            ->assertJsonPath('data.1.pt', 'TOPI');

        $this->putJson('/api/payroll-baselines/employees/'.$employee->id, [
            ...$payload,
            'pt' => 'DPL',
            'gaji_pokok' => 5000000,
        ])->assertOk()->assertJsonPath('data.pt', 'DPL');
        $this->putJson('/api/payroll-baselines/employees/'.$employee->id, [
            ...$payload,
            'pt' => 'TOPI',
            'gaji_pokok' => 7000000,
        ])->assertOk()->assertJsonPath('data.pt', 'TOPI');

        $this->assertDatabaseCount('master_data_komponen_gaji', 2);
        $this->assertDatabaseHas('master_data_komponen_gaji', [
            'employee_id' => $employee->id,
            'pt' => 'DPL',
            'gaji_pokok' => 5000000,
        ]);
        $this->assertDatabaseHas('master_data_komponen_gaji', [
            'employee_id' => $employee->id,
            'pt' => 'TOPI',
            'gaji_pokok' => 7000000,
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'employee_number' => 'EMP-MULTI-BASELINE',
            'branch' => 'DPL',
        ]);

        $this->putJson('/api/payroll-baselines/employees/'.$employee->id, [
            ...$payload,
            'pt' => 'SRT SBY',
            'gaji_pokok' => 9000000,
        ])->assertUnprocessable()->assertJsonValidationErrors('pt');

        $this->putJson('/api/employees/'.$employee->id, [
            'employee_number' => 'EMP-MULTI-BASELINE',
            'name' => 'Karyawan Multi PT',
            'branch' => 'DPL',
            'pts' => ['DPL'],
            'active' => true,
        ])->assertOk()->assertJsonPath('data.pts', ['DPL']);

        $this->assertDatabaseHas('master_data_komponen_gaji', [
            'employee_id' => $employee->id,
            'pt' => 'DPL',
        ]);
        $this->assertDatabaseMissing('master_data_komponen_gaji', [
            'employee_id' => $employee->id,
            'pt' => 'TOPI',
        ]);
    }

    public function test_it_maintains_one_active_company_baseline_and_derives_bpjs_values(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $response = $this->putJson('/api/payroll-baselines/configuration', ['jht_pens' => 5.70, 'jkk_jkm' => 0.54, 'bpjs_p' => 4.00, 'potgn_tk' => 3.00, 'potgn_kes' => 1.00, 'lembur_hari_kerja_rate' => 10000, 'lembur_hari_libur_multiplier' => 2, 'insentif_default' => 10.00]);

        $response->assertCreated()->assertJsonPath('data.tunj_bpjs', 10.24)->assertJsonPath('data.tunj_prshan', 10.24)->assertJsonMissingPath('data.potongan_alpha');
        $this->assertDatabaseCount('master_baseline_payroll', 1);
        $this->assertDatabaseHas('master_baseline_payroll', ['is_active' => true, 'tunj_bpjs' => 10.24, 'tunj_prshan' => 10.24]);
    }
}
