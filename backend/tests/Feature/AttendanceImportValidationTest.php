<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AttendanceImportValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequential_imports_update_known_niks_preserve_omitted_rows_and_skip_unknown_niks(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $employeeA = Employee::create([
            'employee_number' => 'EMP-A',
            'name' => 'Employee A',
            'branch' => 'TOPI',
            'active' => true,
        ]);
        $employeeB = Employee::create([
            'employee_number' => 'EMP-B',
            'name' => 'Employee B',
            'branch' => 'DPL',
            'active' => true,
        ]);
        $employeeC = Employee::create([
            'employee_number' => 'EMP-C',
            'name' => 'Employee C',
            'branch' => 'DPL',
            'active' => true,
        ]);

        $firstFile = $this->attendanceFile([
            ['name' => 'Employee A', 'number' => 'EMP-A', 'status' => 'M'],
            ['name' => 'Employee B', 'number' => 'EMP-B', 'status' => 'M'],
        ]);

        try {
            $this->import($firstFile)->assertOk()->assertJsonCount(0, 'skipped_employees');
        } finally {
            @unlink($firstFile->getPathname());
        }

        $this->assertDatabaseCount('employees', 3);
        $employeeARecord = AttendanceRecord::query()
            ->where('employee_id', $employeeA->id)
            ->firstOrFail();
        $employeeBRecord = AttendanceRecord::query()
            ->where('employee_id', $employeeB->id)
            ->firstOrFail();

        $secondFile = $this->attendanceFile([
            ['name' => 'Employee B From File', 'number' => 'EMP-B', 'status' => 'C'],
            ['name' => 'Employee C', 'number' => 'EMP-C', 'status' => 'M'],
            ['name' => 'Unknown Employee', 'number' => 'EMP-UNKNOWN', 'status' => 'M'],
        ]);

        try {
            $this->import($secondFile)
                ->assertOk()
                ->assertJsonCount(1, 'skipped_employees')
                ->assertJsonPath('skipped_employees.0.number', 'EMP-UNKNOWN')
                ->assertJsonPath(
                    'skipped_employees.0.reason',
                    'Karyawan "Unknown Employee" dengan NIK EMP-UNKNOWN tidak ditemukan di Data Karyawan.'
                );
        } finally {
            @unlink($secondFile->getPathname());
        }

        $this->assertDatabaseCount('employees', 3);
        $this->assertDatabaseCount('attendance_period_employees', 3);
        $this->assertDatabaseCount('attendance_records', 3);

        $this->assertDatabaseHas('employees', [
            'id' => $employeeA->id,
            'employee_number' => 'EMP-A',
            'name' => 'Employee A',
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $employeeARecord->id,
            'employee_id' => $employeeA->id,
            'status' => 'M',
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $employeeB->id,
            'employee_number' => 'EMP-B',
            'name' => 'Employee B',
            'branch' => 'DPL',
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $employeeBRecord->id,
            'employee_id' => $employeeB->id,
            'status' => 'C',
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $employeeC->id,
            'employee_number' => 'EMP-C',
            'name' => 'Employee C',
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employeeC->id,
            'status' => 'M',
        ]);
        $this->assertDatabaseMissing('employees', ['employee_number' => 'EMP-UNKNOWN']);
    }

    public function test_duplicate_nik_values_in_one_file_are_rejected_case_insensitively(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $file = $this->attendanceFile([
            ['name' => 'Employee A', 'number' => 'EMP-A', 'status' => 'M'],
            ['name' => 'Employee B', 'number' => 'emp-a', 'status' => 'C'],
        ]);

        try {
            $this->import($file)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('file');
        } finally {
            @unlink($file->getPathname());
        }

        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_dates_outside_the_selected_period_are_reported_while_valid_dates_are_imported(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $employee = Employee::create([
            'employee_number' => 'EMP-A',
            'name' => 'Employee A',
            'branch' => 'TOPI',
            'active' => true,
        ]);
        $file = $this->attendanceFile(
            [['name' => 'Employee A', 'number' => 'EMP-A', 'status' => 'M']],
            ['2026-07-21', '2026-07-22']
        );

        try {
            $this->import($file)
                ->assertOk()
                ->assertJsonCount(1, 'skipped_dates')
                ->assertJsonPath('skipped_dates.0.date', '2026-07-22')
                ->assertJsonPath(
                    'skipped_dates.0.reason',
                    'Tanggal absensi 22 Jul 2026 berada di luar periode cut-off 22 Jun 2026 sampai 21 Jul 2026 dan tidak diimpor.'
                );
        } finally {
            @unlink($file->getPathname());
        }

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'attendance_date' => '2026-07-21',
            'status' => 'M',
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'attendance_date' => '2026-07-22',
            'status' => 'M',
        ]);
    }

    public function test_topi_workbook_reads_statuses_ignores_off_and_defaults_sick_letter(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $employee = Employee::create([
            'employee_number' => 'EMP-IMAY',
            'name' => 'IMAY FITRI',
            'branch' => 'TOPI',
            'active' => true,
        ]);
        $file = $this->topiAttendanceFile();

        try {
            $this->withHeaders(['Accept' => 'application/json'])->post('/api/attendance/import', [
                'period' => 7,
                'year' => 2026,
                'file' => $file,
            ])->assertOk()->assertJsonCount(0, 'skipped_employees');
        } finally {
            @unlink($file->getPathname());
        }

        $this->assertDatabaseHas('attendance_periods', [
            'starts_on' => '2026-07-22',
            'ends_on' => '2026-08-21',
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'attendance_date' => '2026-08-06',
            'status' => 'S',
            'remarks' => 'SICK_YES',
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'attendance_date' => '2026-07-22',
            'status' => 'M',
        ]);
        $this->assertDatabaseMissing('attendance_records', [
            'employee_id' => $employee->id,
            'attendance_date' => '2026-07-23',
        ]);
        $this->assertDatabaseMissing('attendance_records', [
            'employee_id' => $employee->id,
            'attendance_date' => '2026-07-21',
        ]);
    }

    private function import(UploadedFile $file)
    {
        return $this->withHeaders(['Accept' => 'application/json'])->post('/api/attendance/import', [
            'period' => 6,
            'year' => 2026,
            'file' => $file,
        ]);
    }

    /** @param array<int, array{name: string, number: string, status: string}> $rows */
    private function attendanceFile(array $rows, array $dates = ['2026-07-01']): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Employee Name');
        $sheet->setCellValue('B1', 'Employee ID');
        foreach ($dates as $index => $date) {
            $sheet->setCellValue([3 + $index, 1], new \DateTimeImmutable($date));
        }

        foreach ($rows as $index => $row) {
            $sheetRow = $index + 2;
            $sheet->setCellValue([1, $sheetRow], $row['name']);
            $sheet->setCellValue([2, $sheetRow], $row['number']);
            foreach ($dates as $index => $_date) {
                $sheet->setCellValue([3 + $index, $sheetRow], $row['status']);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'attendance-import-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile(
            $path,
            'attendance.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    private function topiAttendanceFile(): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('TOPI');
        $sheet->setCellValue('A3', 'No');
        $sheet->setCellValue('B3', 'ID');
        $sheet->setCellValue('C3', 'Nama Lengkap');
        $sheet->setCellValue('D3', 'Jabatan');
        $sheet->setCellValue('F4', new \DateTimeImmutable('2026-07-22'));
        $sheet->setCellValue('A5', 1);
        $sheet->setCellValue('C5', 'IMAY FITRI');
        $sheet->setCellValue('F5', '√');
        $sheet->setCellValue('G5', 'OFF');
        $sheet->setCellValue('U5', 'S');

        $path = tempnam(sys_get_temp_dir(), 'topi-attendance-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'Rekap Absensi AGUSTUS 2026 TOPI.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
