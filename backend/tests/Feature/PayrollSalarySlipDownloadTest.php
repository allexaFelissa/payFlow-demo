<?php

namespace Tests\Feature;

use App\Models\AttendancePeriod;
use App\Models\Employee;
use App\Models\PayrollCalculation;
use App\Models\PayrollSalarySlipSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class PayrollSalarySlipDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_payroll_cannot_download_salary_slips(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $period = AttendancePeriod::create([
            'month' => 6,
            'year' => 2026,
            'status' => 'draft',
            'created_by' => $user->id,
        ]);
        $employee = Employee::create([
            'employee_number' => 'EMP-DRAFT',
            'name' => 'Karyawan Draft',
            'branch' => 'DPL',
            'active' => true,
        ]);
        PayrollCalculation::create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'status' => 'draft',
        ]);

        $this->getJson('/api/payroll-salary-slips/'.$period->id.'?pt=DPL')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pt');
    }

    public function test_completed_payroll_downloads_filtered_snapshot_pdfs_in_one_zip(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $period = AttendancePeriod::create([
            'month' => 6,
            'year' => 2026,
            'starts_on' => '2026-05-22',
            'ends_on' => '2026-06-21',
            'status' => 'draft',
            'created_by' => $user->id,
        ]);
        $target = Employee::create([
            'employee_number' => 'EMP-001',
            'name' => 'Budi Santoso',
            'position' => 'Staff',
            'department' => 'Jakarta',
            'branch' => 'DPL',
            'family_status' => 'TK/0',
            'active' => true,
        ]);
        $other = Employee::create([
            'employee_number' => 'EMP-002',
            'name' => 'Siti Rahma',
            'branch' => 'DPL',
            'active' => true,
        ]);

        $targetCalculation = $this->calculation($period, $target, 6000000, 700000);
        $this->calculation($period, $other, 5500000, 500000);
        $targetCalculation->manualAdjustments()->create([
            'employee_id' => $target->id,
            'payroll_period_id' => $period->id,
            'type' => 'income',
            'component_name' => 'Bonus Proyek',
            'amount' => 250000,
        ]);
        $targetCalculation->manualAdjustments()->create([
            'employee_id' => $target->id,
            'payroll_period_id' => $period->id,
            'type' => 'deduction',
            'component_name' => 'Administrasi',
            'amount' => 50000,
        ]);

        $this->postJson('/api/payroll-completions/complete', [
            'payroll_period_id' => $period->id,
            'pt' => 'DPL',
        ])->assertOk();

        $this->assertDatabaseCount('payroll_salary_slip_snapshots', 2);
        $snapshot = PayrollSalarySlipSnapshot::where('employee_number', 'EMP-001')->firstOrFail();
        $this->assertSame('PT. Delima Pinglogistra', $snapshot->payload['company_name']);
        $this->assertEquals(6250000, $snapshot->payload['totals']['total_income']);
        $this->assertEquals(750000, $snapshot->payload['totals']['total_deduction']);
        $this->assertEquals(5500000, $snapshot->payload['totals']['take_home_pay']);
        $this->assertFalse($snapshot->payload['deductions']['potongan_absensi_is_alpha']);

        $target->update(['name' => 'Nama Master Berubah', 'branch' => 'TOPI']);
        $targetCalculation->update(['gross_income' => 99999999]);

        $response = $this->get('/api/payroll-salary-slips/'.$period->id.'?pt=DPL&search=Budi');
        $response->assertOk();
        $this->assertStringContainsString('application/zip', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('Salary Slip - Juni - DPL 2026.zip', (string) $response->headers->get('content-disposition'));

        $zipPath = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertSame(1, $zip->numFiles);
        $fileName = $zip->getNameIndex(0);
        $this->assertSame('Salary Slip DPL - EMP-001 - Budi Santoso - Juni 2026.pdf', $fileName);
        $this->assertStringStartsWith('%PDF-', $zip->getFromName($fileName));
        $zip->close();
        File::delete($zipPath);
    }

    private function calculation(
        AttendancePeriod $period,
        Employee $employee,
        float $grossIncome,
        float $totalDeduction
    ): PayrollCalculation {
        return PayrollCalculation::create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'gaji_pokok' => 3900000,
            'uang_harian' => 1000000,
            'insentif' => 390000,
            'overtime_amount' => 300000,
            'tunj_bpjs_beban_pt' => 410000,
            'bpjs_kes_pt' => 160000,
            'jkk_jkm_pt' => 21000,
            'jht_pens_pt' => 229000,
            'potongan_tunj_pt' => 410000,
            'potongan_jht_pens' => 117000,
            'potongan_bpjs_karyawan' => 39000,
            'potongan_absensi' => 100000,
            'gross_i' => $grossIncome,
            'gross_ii' => $grossIncome - 217000,
            'gross_income' => $grossIncome,
            'total_deduction' => $totalDeduction,
            'take_home_pay' => $grossIncome - $totalDeduction,
            'hadir' => 20,
            'cuti' => 1,
            'status' => 'draft',
        ]);
    }
}
