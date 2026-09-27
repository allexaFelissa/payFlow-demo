<?php

namespace App\Services\Payroll;

use App\Models\AttendancePeriod;
use App\Models\PayrollCalculation;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class Pph21DataExportService
{
    private const FIRST_ROW = 5;
    private const LAST_TEMPLATE_ROW = 56;

    public function create(AttendancePeriod $period, string $pt): array
    {
        $rows = PayrollCalculation::query()->with('employee')
            ->where('payroll_period_id', $period->id)->where('pt', $pt)->get()
            ->sortBy(fn ($row) => mb_strtolower($row->employee?->name ?? ''))->values();
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['pt' => "Belum ada hasil perhitungan payroll untuk PT {$pt} pada periode ini."]);
        }

        $path = resource_path('templates/pph21_data.xlsx');
        if (! is_file($path)) throw new RuntimeException('Template DATA PPh21 tidak tersedia.');
        $spreadsheet = IOFactory::load($path);
        $data = $spreadsheet->getSheetByName('DATA');
        if (! $data) throw new RuntimeException('Sheet DATA tidak ditemukan pada template PPh21.');
        for ($index = $spreadsheet->getSheetCount() - 1; $index >= 0; $index--) {
            if ($spreadsheet->getSheet($index)->getTitle() !== 'DATA') $spreadsheet->removeSheetByIndex($index);
        }
        $spreadsheet->setActiveSheetIndex(0);

        $lastRow = self::FIRST_ROW + $rows->count() - 1;
        if ($lastRow > self::LAST_TEMPLATE_ROW) {
            $data->insertNewRowBefore(self::LAST_TEMPLATE_ROW + 1, $lastRow - self::LAST_TEMPLATE_ROW);
            for ($row = self::LAST_TEMPLATE_ROW + 1; $row <= $lastRow; $row++) {
                $data->duplicateStyle($data->getStyle('A5:U5'), "A{$row}:U{$row}");
                $data->getRowDimension($row)->setRowHeight($data->getRowDimension(5)->getRowHeight());
            }
        }
        $data->setCellValue('B1', '');
        for ($row = self::FIRST_ROW; $row <= max(self::LAST_TEMPLATE_ROW, $lastRow); $row++) {
            foreach (range('A', 'U') as $column) $data->setCellValue("{$column}{$row}", null);
        }

        $source = IOFactory::load($path)->getSheetByName('DATA');
        foreach ($rows as $index => $calculation) {
            $row = self::FIRST_ROW + $index;
            $employee = $calculation->employee;
            $data->setCellValue("B{$row}", (int) $period->month);
            $data->setCellValue("C{$row}", (int) $period->year);
            $data->setCellValue("D{$row}", 'Resident');
            $data->setCellValueExplicit("E{$row}", (string) ($employee?->tax_number ?? ''), DataType::TYPE_STRING);
            $data->setCellValue("G{$row}", $employee?->family_status ?? '');
            $data->setCellValue("H{$row}", $employee?->position ?? '');
            $data->setCellValue("I{$row}", 'N/A');
            $data->setCellValue("J{$row}", '21-100-01');
            $data->setCellValue("K{$row}", round((float) $calculation->gross_ii, 2));
            foreach (['L', 'P', 'Q', 'R'] as $column) {
                $formula = (string) $source->getCell("{$column}5")->getValue();
                $data->setCellValue("{$column}{$row}", str_replace(['G5', 'K5'], ["G{$row}", "K{$row}"], $formula));
            }
        }
        if ($lastRow < self::LAST_TEMPLATE_ROW) $data->removeRow($lastRow + 1, self::LAST_TEMPLATE_ROW - $lastRow);

        $directory = storage_path('app/tmp');
        File::ensureDirectoryExists($directory);
        $output = $directory.'/pph21-data-'.uniqid('', true).'.xlsx';
        (new Xlsx($spreadsheet))->save($output);
        return ['path' => $output, 'name' => "DATA PPh21 - {$pt} - {$period->month}-{$period->year}.xlsx"];
    }
}
