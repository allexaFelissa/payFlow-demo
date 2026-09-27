<?php

namespace Tests\Feature;

use App\Models\AttendancePeriod;
use App\Models\Employee;
use App\Models\EmployeePayrollBaseline;
use App\Models\PayrollCalculation;
use App\Models\PayrollSalarySlipSnapshot;
use App\Models\User;
use App\Services\Payroll\PayrollRecapCategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PayrollRecapDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_korp_bks_recap_places_similar_employee_names_in_the_correct_groups(): void
    {
        $snapshots = collect(['Jumadi SCRT', 'Sony', 'Jumadi'])
            ->map(fn (string $name) => new PayrollSalarySlipSnapshot([
                'employee_name' => $name,
                'payload' => ['employee' => ['name' => $name, 'title' => 'Staff']],
            ]));

        $groups = collect(app(PayrollRecapCategoryService::class)->group($snapshots, 'KORP BKS'))
            ->mapWithKeys(fn (array $group) => [
                $group['name'] => collect($group['snapshots'])->pluck('employee_name')->values()->all(),
            ]);

        $this->assertSame(['Jumadi SCRT', 'Sony'], $groups->get('Back Office'));
        $this->assertSame(['Jumadi'], $groups->get('Operasional'));
        $this->assertArrayNotHasKey('Belum Dipetakan', $groups);
    }

    public function test_requested_employee_aliases_use_the_updated_recap_sections(): void
    {
        $topiSnapshots = collect(['Demo Finance 01', 'Demo Finance 02', 'Demo Finance 03'])
            ->map(fn (string $name) => new PayrollSalarySlipSnapshot([
                'employee_name' => $name,
                'payload' => ['employee' => ['name' => $name, 'title' => 'Finance Staff']],
            ]));
        $topiGroups = collect(app(PayrollRecapCategoryService::class)->group($topiSnapshots, 'TOPI'))
            ->mapWithKeys(fn (array $group) => [$group['name'] => collect($group['snapshots'])->pluck('employee_name')->values()->all()]);

        $this->assertSame(
            ['Demo Finance 01', 'Demo Finance 02', 'Demo Finance 03'],
            $topiGroups->get('Back Office')
        );

        $cikarangSnapshot = collect([new PayrollSalarySlipSnapshot([
            'employee_name' => 'Demo Warehouse 01',
            'payload' => ['employee' => ['name' => 'Demo Warehouse 01', 'title' => 'Warehouse Staff']],
        ])]);
        $cikarangGroups = collect(app(PayrollRecapCategoryService::class)->group($cikarangSnapshot, 'SRT CKRG'));

        $this->assertSame('Back Office', $cikarangGroups->first()['name']);

        $operationsSnapshot = collect([new PayrollSalarySlipSnapshot([
            'employee_name' => 'Demo Operations 01',
            'payload' => ['employee' => ['name' => 'Demo Operations 01', 'title' => 'Operations Staff']],
        ])]);
        $dplGroups = collect(app(PayrollRecapCategoryService::class)->group($operationsSnapshot, 'DPL'));

        $this->assertSame('Operasional', $dplGroups->first()['name']);
    }

    public function test_draft_payroll_cannot_download_payroll_recap(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $period = $this->period($user);
        $employee = $this->employee('EMP-DRAFT', 'Karyawan Draf');
        $this->calculation($period, $employee);

        $this->getJson('/api/payroll-recaps/'.$period->id.'?pt=DPL&transfer_amount=0&cash_amount=0&transfer_count=0&cash_count=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pt')
            ->assertJsonPath(
                'errors.pt.0',
                'Payroll harus diselesaikan terlebih dahulu sebelum Rekap Payroll dapat diunduh.'
            );
    }

    public function test_completed_payroll_downloads_all_final_snapshots_in_template_groups(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $this->travelTo(Carbon::parse('2026-06-25 10:00:00', 'Asia/Jakarta'));
        $period = $this->period($user);
        $names = [
            'Demo Employee Primary',
            'Demo Employee Director',
            'Karyawan Uji 01',
            'Karyawan Uji 02',
            'Karyawan Uji 03',
            'Karyawan Uji 04',
            'Karyawan Uji 05',
            'Karyawan Uji 06',
            'Karyawan Uji 07',
            'Karyawan Uji 08',
            'Karyawan Uji 09',
            'Karyawan Uji 10',
        ];
        $employees = collect();

        foreach ($names as $index => $name) {
            $employee = $this->employee(
                sprintf('EMP-%03d', $index + 1),
                $name,
                $name === 'Demo Employee Director' ? 'HR Staff' : 'Operations Staff'
            );
            $employees->push($employee);
            $this->calculation($period, $employee);
        }

        $this->postJson('/api/payroll-completions/complete', [
            'payroll_period_id' => $period->id,
            'pt' => 'DPL',
        ])->assertOk();

        $primaryEmployee = $employees->firstWhere('name', 'Demo Employee Primary');
        $primarySnapshot = PayrollSalarySlipSnapshot::where('employee_number', $primaryEmployee->employee_number)->firstOrFail();
        $legacyPayload = $primarySnapshot->payload;
        data_set($legacyPayload, 'attendance.alpha', 2);
        data_set($legacyPayload, 'baseline.potongan_alpha', 100000);
        data_set($legacyPayload, 'deductions.potongan_alpha', 200000);
        data_set($legacyPayload, 'deductions.potongan_absensi', 100000);
        unset($legacyPayload['deductions']['potongan_absensi_is_alpha']);
        $primarySnapshot->update(['payload' => $legacyPayload]);

        PayrollCalculation::query()
            ->where('employee_id', $primaryEmployee->id)
            ->update(['gaji_pokok' => 99999999, 'gross_income' => 99999999]);
        $primaryEmployee->update(['name' => 'Demo Master Changed', 'bank_account_number' => null]);

        $this->getJson('/api/payroll-recaps/'.$period->id.'/summary?pt=DPL')
            ->assertOk()
            ->assertJsonPath('data.employee_count', 12)
            ->assertJsonPath('data.total', 63600000);

        $this->getJson('/api/payroll-recaps/'.$period->id.'?pt=DPL&transfer_amount=60000000&cash_amount=10000000&transfer_count=10&cash_count=2')
            ->assertUnprocessable()
            ->assertJsonPath('errors.transfer_amount.0', 'Jumlah transfer dan tunai tidak boleh melebihi total payroll Rp63.600.000.');

        $this->getJson('/api/payroll-recaps/'.$period->id.'?pt=DPL&transfer_amount=53600000&cash_amount=10000000&transfer_count=11&cash_count=2')
            ->assertUnprocessable()
            ->assertJsonPath('errors.transfer_count.0', 'Jumlah karyawan transfer dan tunai harus sama dengan total 12 karyawan.');

        $response = $this->get('/api/payroll-recaps/'.$period->id.'?pt=DPL&transfer_amount=53600000&cash_amount=10000000&transfer_count=10&cash_count=2');
        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $response->headers->get('content-type')
        );
        $this->assertStringContainsString(
            'Rekap Payroll - DPL - Juni 2026.xlsx',
            (string) $response->headers->get('content-disposition')
        );

        $path = $response->baseResponse->getFile()->getPathname();
        $spreadsheet = IOFactory::load($path);
        $worksheet = $spreadsheet->getSheet(0);

        $this->assertSame('Juni', $worksheet->getTitle());
        $this->assertSame('PT. Delima Pinglogistra', $worksheet->getCell('AG1')->getValue());
        $this->assertSame('Juni 2026', $worksheet->getCell('AG2')->getValue());
        $this->assertFalse($worksheet->getColumnDimension('AM')->getVisible());
        $this->assertNull($worksheet->getCell('AM7')->getValue());

        $employeeRows = [];
        $labels = [];
        for ($row = 9; $row <= $worksheet->getHighestDataRow(); $row++) {
            if ((string) $worksheet->getCell('G'.$row)->getValue() !== '') {
                $employeeRows[] = $row;
            }
            $labels[] = $worksheet->getCell('B'.$row)->getValue();
        }

        $this->assertCount(12, $employeeRows);
        $this->assertContains('1. Back Office', $labels);
        $this->assertContains('2. Operasional', $labels);
        $this->assertContains('3. Operation', $labels);
        $this->assertNotContains('3. Belum Dipetakan', $labels);
        $this->assertContains('Total Keseluruhan :', $labels);
        $grandTotalRow = $this->findRow($worksheet, 'Total Keseluruhan :');
        $this->assertNotNull($grandTotalRow);
        $this->assertEquals(63600000, $worksheet->getCell('AY'.$grandTotalRow)->getOldCalculatedValue());

        $primaryRow = $this->findRow($worksheet, 'Demo Employee Primary');
        $this->assertNotNull($primaryRow);
        foreach (range('C', 'F') as $column) {
            $this->assertFalse($worksheet->getColumnDimension($column)->getVisible());
            $this->assertNull($worksheet->getCell($column.'7')->getValue());
            $this->assertNull($worksheet->getCell($column.$primaryRow)->getValue());
        }
        $this->assertSame('Total Alpha', $worksheet->getCell('V7')->getValue());
        $this->assertSame('Potongan Alpha', $worksheet->getCell('W7')->getValue());
        $this->assertSame('Potongan Absensi', $worksheet->getCell('AP7')->getValue());
        $this->assertSame('=V'.$primaryRow.'*100000', $worksheet->getCell('W'.$primaryRow)->getValue());
        $this->assertSame('=W'.$primaryRow.'-100000', $worksheet->getCell('AP'.$primaryRow)->getValue());
        $this->assertSame('GAJI', $worksheet->getCell('AG7')->getValue());
        $this->assertSame('=SUM(X'.$primaryRow.':AF'.$primaryRow.')', $worksheet->getCell('AG'.$primaryRow)->getValue());
        $this->assertEquals(3900000, $worksheet->getCell('X'.$primaryRow)->getValue());
        $this->assertSame('=SUM(AK'.$primaryRow.':AN'.$primaryRow.')+AG'.$primaryRow, $worksheet->getCell('AO'.$primaryRow)->getValue());
        $this->assertSame('=AO'.$primaryRow.'-AP'.$primaryRow.'-AQ'.$primaryRow, $worksheet->getCell('AR'.$primaryRow)->getValue());
        $this->assertEquals(34000, $worksheet->getCell('AW'.$primaryRow)->getValue());
        $this->assertSame('=SUM(AS'.$primaryRow.':AW'.$primaryRow.')', $worksheet->getCell('AX'.$primaryRow)->getValue());
        $this->assertSame('=AR'.$primaryRow.'-AX'.$primaryRow, $worksheet->getCell('AY'.$primaryRow)->getValue());
        $this->assertEquals(700000, $worksheet->getCell('AX'.$primaryRow)->getOldCalculatedValue() + $worksheet->getCell('AP'.$primaryRow)->getOldCalculatedValue() + $worksheet->getCell('AQ'.$primaryRow)->getOldCalculatedValue());
        $this->assertEquals(5300000, $worksheet->getCell('AY'.$primaryRow)->getOldCalculatedValue());
        $this->assertEquals(5590000, $worksheet->getCell('AG'.$primaryRow)->getOldCalculatedValue());
        $summarySheet = $spreadsheet->getSheetByName('REKAP');
        $this->assertSame('PT. Delima Pinglogistra (DPL)', $summarySheet->getCell('D5')->getValue());
        $this->assertSame('REKAP GAJI : Juni 2026', $summarySheet->getCell('B7')->getValue());
        $this->assertSame('TANGGAL PEMBAYARAN : 25 Juni 2026', $summarySheet->getCell('B8')->getValue());
        $this->assertSame('PENDAPATAN BRUTO', $summarySheet->getCell('F15')->getValue());
        $this->assertSame('PENDAPATAN BERSIH', $summarySheet->getCell('F20')->getValue());
        $this->assertSame('Transfer melalui Bank', $summarySheet->getCell('F24')->getValue());
        $this->assertSame(10, $summarySheet->getCell('H24')->getValue());
        $this->assertEquals(53600000, $summarySheet->getCell('I24')->getValue());
        $this->assertSame(2, $summarySheet->getCell('H25')->getValue());
        $this->assertEquals(10000000, $summarySheet->getCell('I25')->getValue());
        $this->assertSame('=SUM(I24:I25)', $summarySheet->getCell('J25')->getValue());
        $this->assertSame('=I15-SUM(H24:H25)', $summarySheet->getCell('H26')->getValue());
        $this->assertSame('=J20-SUM(I24:I25)', $summarySheet->getCell('J26')->getValue());
        $this->assertEquals(12, $summarySheet->getCell('I15')->getOldCalculatedValue());
        $this->assertEquals(63600000, $summarySheet->getCell('J20')->getOldCalculatedValue());
        $this->assertEquals(63600000, $summarySheet->getCell('J25')->getOldCalculatedValue());
        $this->assertEquals(0, $summarySheet->getCell('H26')->getOldCalculatedValue());
        $this->assertEquals(0, $summarySheet->getCell('J26')->getOldCalculatedValue());
        $this->assertSame('FF9BD5E0', $summarySheet->getStyle('J26')->getFill()->getStartColor()->getARGB());
        $this->assertTrue($summarySheet->getStyle('J26')->getFont()->getBold());
        $this->assertSame('Jakarta, 25 Juni 2026', $summarySheet->getCell('D31')->getValue());
        $this->assertNull($summarySheet->getCell('D38')->getValue());
        $this->assertNull($summarySheet->getCell('H38')->getValue());
        $this->assertNull($summarySheet->getCell('J38')->getValue());
        foreach (['N15', 'N16', 'O16', 'P16', 'N17', 'O17', 'P17', 'O18', 'P18', 'O19', 'P19', 'M26', 'N31'] as $clearedCell) {
            $this->assertNull($summarySheet->getCell($clearedCell)->getValue());
        }
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $this->assertSame([], $sheet->getComments());
        }

        $spreadsheet->disconnectWorksheets();
        File::delete($path);
    }

    private function period(User $user): AttendancePeriod
    {
        return AttendancePeriod::create([
            'month' => 6,
            'year' => 2026,
            'starts_on' => '2026-05-22',
            'ends_on' => '2026-06-21',
            'status' => 'draft',
            'created_by' => $user->id,
        ]);
    }

    private function employee(string $number, string $name, string $position = 'Staff'): Employee
    {
        return Employee::create([
            'employee_number' => $number,
            'name' => $name,
            'position' => $position,
            'department' => 'Jakarta',
            'branch' => 'DPL',
            'family_status' => 'TK/0',
            'bank_account_number' => '0001234567',
            'join_date' => '2024-01-15',
            'active' => true,
        ]);
    }

    private function calculation(AttendancePeriod $period, Employee $employee): PayrollCalculation
    {
        EmployeePayrollBaseline::firstOrCreate(
            ['employee_id' => $employee->id, 'pt' => 'DPL'],
            ['potongan_alpha' => 100000]
        );

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
            'gross_i' => 6000000,
            'gross_ii' => 5783000,
            'gross_income' => 6000000,
            'total_deduction' => 700000,
            'take_home_pay' => 5300000,
            'hadir' => 20,
            'cuti' => 1,
            'status' => 'draft',
        ]);
    }

    private function findRow($worksheet, string $name): ?int
    {
        for ($row = 9; $row <= $worksheet->getHighestDataRow(); $row++) {
            if ($worksheet->getCell('B'.$row)->getValue() === $name) {
                return $row;
            }
        }

        return null;
    }
}
