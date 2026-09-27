<?php

namespace Tests\Feature;

use App\Enums\AttendancePeriodStatus;
use App\Models\AttendancePeriod;
use App\Models\Employee;
use App\Models\Overtime;
use App\Models\OvertimeDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class OvertimeImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_upload_replaces_included_employees_and_preserves_omitted_employees(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $period = AttendancePeriod::create([
            'month' => 7, 'year' => 2026, 'starts_on' => '2026-06-22', 'ends_on' => '2026-07-21',
            'status' => AttendancePeriodStatus::Draft, 'created_by' => User::query()->value('id'),
        ]);
        $employeeA = Employee::create(['employee_number' => 'A1', 'name' => 'Employee A', 'branch' => 'DPL', 'active' => true]);
        $employeeB = Employee::create(['employee_number' => 'B2', 'name' => 'Employee B', 'branch' => 'DPL', 'active' => true]);
        $employeeC = Employee::create(['employee_number' => 'C3', 'name' => 'Employee C', 'branch' => 'DPL', 'active' => true]);

        $first = $this->workbook([
            ['title' => 'Employee A A1', 'date' => '2026-07-01', 'start' => '16:00', 'end' => '19:00'],
            ['title' => 'Employee B B2', 'date' => '2026-07-02', 'start' => '16:00', 'end' => '18:00'],
        ]);
        $this->upload($first)->assertOk()->assertJsonPath('imported_employees', 2);

        $second = $this->workbook([
            ['title' => 'Employee B B2', 'date' => '2026-07-03', 'start' => '20:00', 'end' => '02:00'],
            ['title' => 'Employee C', 'date' => '2026-07-04', 'start' => '14:30', 'end' => '18:00', 'holiday' => true],
        ]);
        $this->upload($second)->assertOk()->assertJsonPath('imported_employees', 2);

        $overtimeA = Overtime::where(['payroll_period_id' => $period->id, 'employee_id' => $employeeA->id])->firstOrFail();
        $overtimeB = Overtime::where(['payroll_period_id' => $period->id, 'employee_id' => $employeeB->id])->firstOrFail();
        $overtimeC = Overtime::where(['payroll_period_id' => $period->id, 'employee_id' => $employeeC->id])->firstOrFail();
        $this->assertDatabaseHas('overtime_details', ['overtime_id' => $overtimeA->id, 'attendance_date' => '2026-07-01', 'duration_minutes' => 180]);
        $this->assertDatabaseMissing('overtime_details', ['overtime_id' => $overtimeB->id, 'attendance_date' => '2026-07-02']);
        $this->assertDatabaseHas('overtime_details', ['overtime_id' => $overtimeB->id, 'attendance_date' => '2026-07-03', 'duration_minutes' => 360]);
        $this->assertDatabaseHas('overtime_details', ['overtime_id' => $overtimeC->id, 'attendance_date' => '2026-07-04', 'duration_minutes' => 210, 'is_holiday' => true]);
        $this->assertSame(3, OvertimeDetail::count());
    }

    public function test_import_returns_sheet_specific_error_when_employee_is_not_found(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        AttendancePeriod::create([
            'month' => 7, 'year' => 2026, 'starts_on' => '2026-06-22', 'ends_on' => '2026-07-21',
            'status' => AttendancePeriodStatus::Draft, 'created_by' => User::query()->value('id'),
        ]);

        $file = $this->workbook([
            ['title' => 'Karyawan Tidak Dikenal X999', 'date' => '2026-07-04', 'start' => '16:00', 'end' => '19:00'],
        ]);

        $this->upload($file)
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'Sheet "Karyawan Tidak Dikenal X999" tidak dapat diimpor: karyawan dengan NIK X999 tidak ditemukan secara unik di Data Karyawan.');

        $this->assertDatabaseCount('overtimes', 0);
    }

    public function test_all_overtime_dates_are_imported_regardless_of_payroll_cutoff(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $period = AttendancePeriod::create([
            'month' => 7, 'year' => 2026, 'starts_on' => '2026-06-22', 'ends_on' => '2026-07-21',
            'status' => AttendancePeriodStatus::Draft, 'created_by' => User::query()->value('id'),
        ]);
        $employee = Employee::create(['employee_number' => 'A1', 'name' => 'Employee A', 'branch' => 'DPL', 'active' => true]);
        $file = $this->workbook([[
            'title' => 'Employee A A1',
            'rows' => [
                ['date' => '2026-07-21', 'start' => '16:00', 'end' => '19:00'],
                ['date' => '2026-07-22', 'start' => '16:00', 'end' => '18:00'],
            ],
        ]]);

        $this->upload($file)
            ->assertOk()
            ->assertJsonPath('imported_employees', 1)
            ->assertJsonCount(0, 'skipped_employees');

        $overtime = Overtime::where(['payroll_period_id' => $period->id, 'employee_id' => $employee->id])->firstOrFail();
        $this->assertDatabaseHas('overtime_details', ['overtime_id' => $overtime->id, 'attendance_date' => '2026-07-21']);
        $this->assertDatabaseHas('overtime_details', ['overtime_id' => $overtime->id, 'attendance_date' => '2026-07-22']);
    }

    public function test_employee_sheet_is_imported_when_all_overtime_dates_are_outside_period(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $period = AttendancePeriod::create([
            'month' => 7, 'year' => 2026, 'starts_on' => '2026-06-22', 'ends_on' => '2026-07-21',
            'status' => AttendancePeriodStatus::Draft, 'created_by' => User::query()->value('id'),
        ]);
        $validEmployee = Employee::create(['employee_number' => 'A1', 'name' => 'Employee A', 'branch' => 'DPL', 'active' => true]);
        $dwi = Employee::create(['employee_number' => 'D2', 'name' => 'Dwi Septi', 'branch' => 'DPL', 'active' => true]);
        $file = $this->workbook([
            ['title' => 'Employee A A1', 'date' => '2026-07-21', 'start' => '16:00', 'end' => '19:00'],
            ['title' => 'Dwi Septi D2', 'date' => '2026-07-22', 'start' => '16:00', 'end' => '18:00'],
        ]);

        $this->upload($file)
            ->assertOk()
            ->assertJsonPath('imported_employees', 2)
            ->assertJsonCount(0, 'skipped_employees');

        $this->assertDatabaseHas('overtimes', ['payroll_period_id' => $period->id, 'employee_id' => $validEmployee->id]);
        $this->assertDatabaseHas('overtimes', ['payroll_period_id' => $period->id, 'employee_id' => $dwi->id]);
    }

    public function test_topi_attendance_workbook_imports_overtime_hours_and_amount_from_columns_q_to_w(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $period = AttendancePeriod::create([
            'month' => 8, 'year' => 2026, 'starts_on' => '2026-07-22', 'ends_on' => '2026-08-21',
            'status' => AttendancePeriodStatus::Draft, 'created_by' => User::query()->value('id'),
        ]);
        $employee = Employee::create(['employee_number' => 'A1', 'name' => 'Employee A', 'branch' => 'DPL', 'active' => true]);
        $file = $this->topiWorkbook();

        $this->post('/api/overtime/import', ['period' => 7, 'year' => 2026, 'file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('imported_employees', 1);

        $overtime = Overtime::where(['payroll_period_id' => $period->id, 'employee_id' => $employee->id])->firstOrFail();
        $this->assertSame('3.50', $overtime->hours);
        $this->assertSame('35000.00', $overtime->amount);
        $this->assertDatabaseHas('overtime_details', [
            'overtime_id' => $overtime->id,
            'attendance_date' => '2026-08-05',
            'duration_minutes' => 270,
        ]);
    }

    private function upload(UploadedFile $file)
    {
        return $this->post('/api/overtime/import', ['period' => 6, 'year' => 2026, 'file' => $file], ['Accept' => 'application/json']);
    }

    private function workbook(array $employees): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        foreach ($employees as $employee) {
            $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, $employee['title']);
            $spreadsheet->addSheet($sheet);
            $sheet->setCellValue('B4', 'LEMBUR');
            $rows = $employee['rows'] ?? [$employee];
            foreach ($rows as $index => $row) {
                $sheetRow = 7 + $index;
                $sheet->setCellValue([2, $sheetRow], new \DateTimeImmutable($row['date']));
                $sheet->setCellValue([4, $sheetRow], $row['start']);
                $sheet->setCellValue([5, $sheetRow], $row['end']);
                $sheet->setCellValue([8, $sheetRow], ($row['holiday'] ?? false) ? '=(I$7/7)*G7' : '=G7*I$8');
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'overtime-import-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        return new UploadedFile($path, 'lembur.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function topiWorkbook(): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Employee A');
        $sheet->setCellValue('B7', 'Employee A');
        $sheet->setCellValue('Q4', 'LEMBUR');
        $sheet->setCellValue('Q7', new \DateTimeImmutable('2026-08-05'));
        $sheet->setCellValue('S7', '16:00');
        $sheet->setCellValue('T7', '20:30');
        $sheet->setCellValue('V7', 3.5);
        $sheet->setCellValue('W7', 35000);

        $path = tempnam(sys_get_temp_dir(), 'topi-overtime-import-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'topi-lembur.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
