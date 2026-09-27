<?php

namespace App\Services\Overtime;

use App\Models\AttendancePeriod;
use App\Models\Employee;
use App\Models\Overtime;
use App\Models\PayrollBaseline;
use App\Services\Payroll\PayrollPeriodService;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class OvertimeImportService
{
    public function __construct(
        private readonly OvertimeRateConverter $rateConverter,
        private readonly OvertimeAmountCalculator $amountCalculator,
        private readonly ExtraTimeService $extraTime,
    ) {}

    /** @return array{period: AttendancePeriod, imported: int, skipped: array<int, array{sheet: string, number: string, reason: string}>} */
    public function import(UploadedFile $file, int $month, int $year): array
    {
        $period = app(PayrollPeriodService::class)->attendancePeriod($year, $month)
            ?? throw ValidationException::withMessages(['file' => 'Periode absensi belum tersedia. Impor absensi terlebih dahulu.']);
        $parsed = $this->parse($file);
        $skipped = [];
        $imports = [];

        foreach ($parsed as $sheet) {
            foreach ($sheet['skipped'] as $reason) {
                $skipped[] = [
                    'sheet' => $sheet['sheet'],
                    'name' => $sheet['name'],
                    'number' => $sheet['number'] ?? '',
                    'reason' => $reason,
                ];
            }
            if ($sheet['rows'] === []) {
                continue;
            }
            $employee = $this->employee($sheet['number'], $sheet['name']);
            if (! $employee) {
                $identifier = $sheet['number'] ? "NIK {$sheet['number']}" : "nama {$sheet['name']}";
                $skipped[] = [
                    'sheet' => $sheet['sheet'],
                    'name' => $sheet['name'],
                    'number' => $sheet['number'] ?? '',
                    'reason' => "Sheet \"{$sheet['sheet']}\" tidak dapat diimpor: karyawan dengan {$identifier} tidak ditemukan secara unik di Data Karyawan.",
                ];
                continue;
            }
            if ($this->extraTime->eligible($employee)) {
                $skipped[] = [
                    'sheet' => $sheet['sheet'],
                    'name' => $sheet['name'],
                    'number' => $sheet['number'] ?? '',
                    'reason' => "Sheet \"{$sheet['sheet']}\" tidak dapat diimpor: {$employee->name} adalah Koordinator dan menggunakan Extra Time, bukan Lembur.",
                ];
                continue;
            }
            // If a workbook contains the same employee more than once, its last sheet wins.
            $imports[$employee->id] = [
                'employee' => $employee,
                'rows' => $sheet['rows'],
                'hours' => $sheet['hours'],
                'amount' => $sheet['amount'],
            ];
        }

        if ($imports === [] && $skipped !== []) {
            throw ValidationException::withMessages([
                'file' => array_map(fn (array $item) => $item['reason'], $skipped),
            ]);
        }

        DB::transaction(function () use ($period, $imports) {
            $configuration = PayrollBaseline::active()->latest('id')->first();
            foreach ($imports as $import) {
                /** @var Employee $employee */
                $employee = $import['employee'];
                $overtime = Overtime::firstOrCreate(
                    ['employee_id' => $employee->id, 'payroll_period_id' => $period->id],
                    ['hours' => 0, 'hourly_rate' => (float) ($configuration?->lembur_hari_kerja_rate ?? 0), 'amount' => 0]
                );

                // A newer sheet is authoritative for this employee only.
                $overtime->details()->delete();
                foreach ($import['rows'] as $row) {
                    $overtime->details()->create($row);
                }

                $details = $overtime->details()->get();
                $hours = $import['hours'] ?? round($details->sum(fn ($detail) => $this->rateConverter->fromDurationMinutes((int) $detail->duration_minutes)), 2);
                $employee->loadMissing('payrollBaselines');
                $baseline = $employee->payrollBaselineForPt($employee->primaryPayrollPt());
                $amount = $import['amount'] ?? ($configuration ? $this->amountCalculator->calculate($details, (float) ($baseline?->uang_harian ?? 0), $configuration)['amount'] : 0);
                $overtime->update(['hours' => $hours, 'hourly_rate' => (float) ($configuration?->lembur_hari_kerja_rate ?? 0), 'amount' => $amount]);
            }
        });

        return ['period' => $period->refresh(), 'imported' => count($imports), 'skipped' => $skipped];
    }

    private function parse(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheets = [];
        $errors = [];
        $overtimeSheetsFound = 0;

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $layout = $this->overtimeLayout($sheet);
            if (! $layout) {
                continue;
            }
            $overtimeSheetsFound++;
            $title = trim($sheet->getTitle());
            $number = null;
            $name = trim((string) $sheet->getCell($layout['name'])->getFormattedValue()) ?: $title;
            if (preg_match('/^(.*?)\s+([[:alpha:]]+\d+)$/u', $title, $match)) {
                $name = trim($match[1]);
                $number = trim($match[2]);
            }

            $rows = [];
            $seenDates = [];
            $skipped = [];
            $sourceHours = 0.0;
            $sourceAmount = 0.0;
            $hasSourceTotals = false;
            for ($row = $layout['first_row']; $row <= $sheet->getHighestDataRow(); $row++) {
                $date = $this->date($this->cellValue($sheet->getCell([$layout['date'], $row])));
                if (! $date) {
                    continue;
                }
                $dateKey = $date->toDateString();
                if (isset($seenDates[$dateKey])) {
                    $errors[] = "Tanggal {$dateKey} duplikat pada sheet {$sheet->getTitle()}.";
                    continue;
                }
                $seenDates[$dateKey] = true;
                $start = $this->time($this->cellValue($sheet->getCell([$layout['start'], $row])));
                $end = $this->time($this->cellValue($sheet->getCell([$layout['end'], $row])));
                if (! $start && ! $end) {
                    continue;
                }
                if (! $start || ! $end) {
                    $errors[] = "Jam mulai dan selesai pada {$dateKey} di sheet {$sheet->getTitle()} harus diisi bersamaan.";
                    continue;
                }
                $startMinutes = ((int) substr($start, 0, 2) * 60) + (int) substr($start, 3, 2);
                $endMinutes = ((int) substr($end, 0, 2) * 60) + (int) substr($end, 3, 2);
                if ($endMinutes <= $startMinutes) {
                    $endMinutes += 24 * 60;
                }
                $rows[] = [
                    'attendance_date' => $dateKey,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'duration_minutes' => $endMinutes - $startMinutes,
                    'is_holiday' => $this->isHolidayFormula($sheet->getCell([$layout['amount_formula'], $row])->getValue()),
                ];
                if ($layout['hours'] && $layout['amount']) {
                    $hours = $this->numeric($this->cellValue($sheet->getCell([$layout['hours'], $row])));
                    $amount = $this->numeric($this->cellValue($sheet->getCell([$layout['amount'], $row])));
                    if ($hours !== null || $amount !== null) {
                        $sourceHours += $hours ?? 0;
                        $sourceAmount += $amount ?? 0;
                        $hasSourceTotals = true;
                    }
                }
            }
            $sheets[] = ['sheet' => $title, 'name' => $name, 'number' => $number, 'rows' => $rows, 'hours' => $hasSourceTotals ? round($sourceHours, 2) : null, 'amount' => $hasSourceTotals ? round($sourceAmount, 2) : null, 'skipped' => $skipped];
        }

        if ($overtimeSheetsFound === 0) {
            $errors[] = 'Tidak ditemukan sheet lembur. Pastikan file memiliki blok bertuliskan LEMBUR.';
        }
        if ($errors) {
            throw ValidationException::withMessages(['file' => $errors]);
        }

        return $sheets;
    }

    private function overtimeLayout($sheet): ?array
    {
        if (mb_strtoupper(trim((string) $sheet->getCell('B4')->getFormattedValue())) === 'LEMBUR') {
            return ['name' => 'B7', 'first_row' => 7, 'date' => 2, 'start' => 4, 'end' => 5, 'hours' => null, 'amount' => null, 'amount_formula' => 8];
        }

        foreach ([3, 4] as $markerRow) {
            if (mb_strtoupper(trim((string) $sheet->getCell("Q{$markerRow}")->getFormattedValue())) === 'LEMBUR') {
                return ['name' => 'B7', 'first_row' => 5, 'date' => 17, 'start' => 19, 'end' => 20, 'hours' => 22, 'amount' => 23, 'amount_formula' => 23];
            }
        }

        return null;
    }

    private function employee(?string $number, string $name): ?Employee
    {
        if ($number) {
            return Employee::query()->whereRaw('LOWER(employee_number) = ?', [mb_strtolower($number)])->first();
        }

        $normalized = $this->normalizedName($name);
        $matches = Employee::query()->get()->filter(fn (Employee $employee) => $this->namesMatch($normalized, $this->normalizedName($employee->name)));

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function normalizedName(string $name): string
    {
        $name = mb_strtolower($name);
        $name = preg_replace('/[^\pL\pN]+/u', ' ', $name);
        $tokens = array_values(array_filter(explode(' ', trim($name)), fn (string $token) => ! in_array($token, ['bu', 'pak', 'spv', 'manager', 'gm', 'resign'], true)));

        return implode(' ', $tokens);
    }

    private function namesMatch(string $source, string $employee): bool
    {
        if ($source === '' || $employee === '') {
            return false;
        }
        if ($source === $employee) {
            return true;
        }

        $compactSource = str_replace(' ', '', $source);
        $compactEmployee = str_replace(' ', '', $employee);
        if (str_starts_with($compactEmployee, $compactSource) || str_starts_with($compactSource, $compactEmployee)) {
            return true;
        }

        $employeeTokens = explode(' ', $employee);
        $position = 0;
        foreach (explode(' ', $source) as $sourceToken) {
            $matched = false;
            for (; $position < count($employeeTokens); $position++) {
                if (str_starts_with($employeeTokens[$position], $sourceToken) || str_starts_with($sourceToken, $employeeTokens[$position])) {
                    $matched = true;
                    $position++;
                    break;
                }
            }
            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    private function numeric(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function date(mixed $value): ?Carbon
    {
        try {
            if (is_numeric($value)) return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
            if ($value instanceof DateTimeInterface) return Carbon::instance($value)->startOfDay();
            return is_string($value) && trim($value) !== '' ? Carbon::parse($value)->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function cellValue($cell): mixed
    {
        try {
            return $cell->getCalculatedValue();
        } catch (\Throwable) {
            return $cell->getValue();
        }
    }

    private function isHolidayFormula(mixed $formula): bool
    {
        if (! is_string($formula) || ! str_starts_with(ltrim($formula), '=')) {
            return false;
        }

        $normalized = mb_strtoupper(preg_replace('/\s+/', '', $formula));

        // Holiday rows use a daily amount cell (I or X) divided by seven hours.
        // Workday rows multiply the overtime hours by the hourly-rate cell.
        return (bool) preg_match('/\$?[IX]\$?\d+\/7/', $normalized);
    }

    private function time(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) {
            $seconds = (int) round((((float) $value) - floor((float) $value)) * 86400) % 86400;
            return sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }
        $text = trim((string) $value);
        if (! preg_match('/^(\d{1,2}):(\d{2})/', $text, $match)) return null;
        $hour = (int) $match[1];
        $minute = (int) $match[2];
        return $hour <= 23 && $minute <= 59 ? sprintf('%02d:%02d', $hour, $minute) : null;
    }
}
