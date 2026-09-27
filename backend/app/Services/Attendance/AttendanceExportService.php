<?php

namespace App\Services\Attendance;

use App\Models\AttendancePeriod;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AttendanceExportService
{
    /**
     * Create an .xlsx file that can be imported again by AttendanceImportService.
     */
    public function export(AttendancePeriod $period): string
    {
        $dates = $period->dates()->orderBy('attendance_date')->pluck('attendance_date')
            ->map(fn ($date) => Carbon::parse($date)->startOfDay())->values();
        $employees = $period->employeePeriods()->with([
            'employee',
            'employee.attendanceRecords' => fn ($query) => $query->where('attendance_period_id', $period->id),
        ])->get()->sortBy(fn ($row) => $row->employee?->name ?? '');

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Absensi');
        $sheet->setCellValue('A1', 'Nama Karyawan');
        $sheet->setCellValue('B1', 'NIK Karyawan');
        $sheet->mergeCells('A1:A2');
        $sheet->mergeCells('B1:B2');

        $this->writeDateHeaders($sheet, $dates);

        $row = 3;
        foreach ($employees as $periodEmployee) {
            $employee = $periodEmployee->employee;
            if (! $employee) {
                continue;
            }
            $records = $employee->attendanceRecords->keyBy(fn ($record) => $record->attendance_date->toDateString());
            $sheet->setCellValue([1, $row], $employee->name);
            $sheet->setCellValueExplicit([2, $row], (string) $employee->employee_number, DataType::TYPE_STRING);
            foreach ($dates as $index => $date) {
                $status = $records->get($date->toDateString())?->status?->value;
                $sheet->setCellValue([$index + 3, $row], $status === 'M' ? '✔' : $status);
            }
            $row++;
        }

        $lastColumn = Coordinate::stringFromColumnIndex(max(2, $dates->count() + 2));
        $sheet->getStyle('A1:'.$lastColumn.'2')->getFont()->setBold(true);
        $sheet->getStyle('A1:'.$lastColumn.'2')->getAlignment()->setHorizontal('center')->setVertical('center');
        $sheet->freezePane('C3');
        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(16);
        for ($column = 3; $column <= $dates->count() + 2; $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth(5);
        }

        $directory = storage_path('app/exports');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/attendance-'.$period->year.'-'.str_pad((string) $period->month, 2, '0', STR_PAD_LEFT).'-'.uniqid().'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    private function writeDateHeaders($sheet, $dates): void
    {
        $groupStart = 3;
        $currentMonth = null;

        foreach ($dates as $index => $date) {
            $column = $index + 3;
            $month = $date->format('Y-m');
            if ($currentMonth !== null && $month !== $currentMonth) {
                $this->mergeMonthHeader($sheet, $groupStart, $column - 1, $dates[$index - 1]);
                $groupStart = $column;
            }
            $currentMonth = $month;
            $sheet->setCellValue([$column, 2], $date->day);
        }

        if ($dates->isNotEmpty()) {
            $this->mergeMonthHeader($sheet, $groupStart, $dates->count() + 2, $dates->last());
        }
    }

    private function mergeMonthHeader($sheet, int $startColumn, int $endColumn, Carbon $date): void
    {
        $start = Coordinate::stringFromColumnIndex($startColumn);
        $end = Coordinate::stringFromColumnIndex($endColumn);
        $sheet->setCellValue([$startColumn, 1], $date->locale('id')->translatedFormat('F Y'));
        if ($startColumn !== $endColumn) {
            $sheet->mergeCells($start.'1:'.$end.'1');
        }
    }
}
