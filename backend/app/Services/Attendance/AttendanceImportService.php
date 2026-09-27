<?php

namespace App\Services\Attendance;

use App\Enums\AttendancePeriodStatus;
use App\Models\AttendancePeriod;
use App\Models\AttendancePeriodDate;
use App\Models\AttendancePeriodEmployee;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\Payroll\PayrollPeriodService;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class AttendanceImportService
{
    /** @return array{period: AttendancePeriod, skipped: array<int, array{name: string, number: string, reason: string}>, skipped_dates: array<int, array{date: string, reason: string}>} */
    public function import(UploadedFile $file, int $month, int $year, int $userId): array
    {
        $payrollPeriod = app(PayrollPeriodService::class)->dates($year, $month);
        $startsOn = $payrollPeriod['start'];
        $endsOn = $payrollPeriod['end'];
        $parsed = $this->parse($file, $startsOn, $endsOn);
        $skippedDates = collect($parsed['dates'])
            ->reject(fn (array $column) => $column['date']->betweenIncluded($startsOn, $endsOn))
            ->map(fn (array $column) => [
                'date' => $column['date']->toDateString(),
                'reason' => sprintf(
                    'Tanggal absensi %s berada di luar periode cut-off %s sampai %s dan tidak diimpor.',
                    $column['date']->format('d M Y'),
                    $startsOn->format('d M Y'),
                    $endsOn->format('d M Y')
                ),
            ])
            ->values()
            ->all();
        $parsed['dates'] = collect($parsed['dates'])
            ->filter(fn (array $column) => $column['date']->betweenIncluded($startsOn, $endsOn))
            ->values()
            ->all();

        if ($parsed['dates'] === []) {
            throw ValidationException::withMessages([
                'file' => array_column($skippedDates, 'reason'),
            ]);
        }

        $matchedEmployees = [];
        $skipped = [];
        $numbers = collect($parsed['rows'])
            ->pluck('number')->filter()->map(fn (string $number) => $this->normalizedEmployeeNumber($number))->unique()->values();
        $employeesByNumber = $numbers->isEmpty()
            ? collect()
            : Employee::query()->whereIn(DB::raw('LOWER(employee_number)'), $numbers)->get()
                ->keyBy(fn (Employee $employee) => $this->normalizedEmployeeNumber($employee->employee_number));
        foreach ($parsed['rows'] as $row) {
            $normalizedNumber = $row['number'] !== '' ? $this->normalizedEmployeeNumber($row['number']) : null;
            $employee = $normalizedNumber
                ? $employeesByNumber->get($normalizedNumber)
                : $this->employeeByUniqueName($row['name']);

            if (! $employee) {
                $skipped[] = [
                    'name' => $row['name'],
                    'number' => $row['number'],
                    'reason' => sprintf(
                        $normalizedNumber
                            ? 'Karyawan "%s" dengan NIK %s tidak ditemukan di Data Karyawan.'
                            : 'Karyawan "%s" tidak dapat dicocokkan secara unik berdasarkan nama karena file tidak berisi NIK.',
                        $row['name'],
                        $row['number']
                    ),
                ];

                continue;
            }

            $matchedEmployees[$row['key']] = $employee;
        }

        $period = DB::transaction(function () use ($parsed, $matchedEmployees, $userId, $startsOn, $endsOn) {
            $period = AttendancePeriod::firstOrCreate(
                ['month' => $endsOn->month, 'year' => $endsOn->year],
                ['status' => AttendancePeriodStatus::Draft, 'created_by' => $userId]
            );

            if ($period->status === AttendancePeriodStatus::Closed) {
                throw ValidationException::withMessages(['file' => 'This attendance period is closed and cannot be imported.']);
            }

            if ($period->status === AttendancePeriodStatus::Locked) {
                $period->update(['status' => AttendancePeriodStatus::Draft]);
            }

            $now = now();
            AttendancePeriodDate::insertOrIgnore(array_map(fn (array $column) => [
                'attendance_period_id' => $period->id,
                'attendance_date' => $column['date']->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ], $parsed['dates']));

            $importedDates = array_map(
                fn (array $column) => $column['date']->toDateString(),
                $parsed['dates']
            );

            foreach ($parsed['rows'] as $row) {
                $employee = $matchedEmployees[$row['key']] ?? null;
                if (! $employee) {
                    continue;
                }

                AttendancePeriodEmployee::firstOrCreate([
                    'attendance_period_id' => $period->id,
                    'employee_id' => $employee->id,
                ]);

                $records = [];
                $populatedDates = [];
                foreach ($parsed['dates'] as $column) {
                    $code = $row['values'][$column['column']] ?? null;
                    if ($code === null) {
                        continue;
                    }
                    $date = $column['date']->toDateString();
                    $populatedDates[] = $date;
                    $records[] = [
                        'attendance_period_id' => $period->id,
                        'employee_id' => $employee->id,
                        'attendance_date' => $date,
                        'status' => $code,
                        'remarks' => $code === 'S' ? 'SICK_YES' : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($records) {
                    AttendanceRecord::upsert(
                        $records,
                        ['employee_id', 'attendance_date', 'attendance_period_id'],
                        ['status', 'remarks', 'updated_at']
                    );
                }

                $emptyDates = array_values(array_diff($importedDates, $populatedDates));
                if ($emptyDates) {
                    AttendanceRecord::query()
                        ->where('attendance_period_id', $period->id)
                        ->where('employee_id', $employee->id)
                        ->whereIn('attendance_date', $emptyDates)
                        ->delete();
                }
            }

            $period->update([
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
                'imported_at' => now(),
            ]);

            return $period->refresh();
        });

        return ['period' => $period, 'skipped' => $skipped, 'skipped_dates' => $skippedDates];
    }

    public function preview(UploadedFile $file): array
    {
        $parsed = $this->parse($file);
        $start = $parsed['dates'][0]['date'];
        $end = $parsed['dates'][count($parsed['dates']) - 1]['date'];

        return ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'label' => 'Periode yang terbaca: '.$start->locale('id')->translatedFormat('d F Y').' - '.$end->locale('id')->translatedFormat('d F Y'), 'year' => $start->year, 'period' => $start->month];
    }

    private function parse(UploadedFile $file, ?Carbon $periodStart = null, ?Carbon $periodEnd = null): array
    {
        $reader = IOFactory::createReaderForFile($file->getRealPath());
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($file->getRealPath());
        $sheet = $this->worksheetForPeriod($workbook, $periodStart, $periodEnd);
        $layout = $this->findHeaderLayout($sheet);
        $headerRow = $layout['employee_header_row'];
        $nameColumn = $layout['name_column'] ?? 1;
        $numberColumn = $layout['number_column'] ?? 2;
        $dateStartColumn = $layout['date_start_column'] ?? 3;
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $highestRow = $sheet->getHighestDataRow();
        $errors = [];
        $dates = [];
        $seenDates = [];

        $dateEndColumn = min($highestColumn, $layout['date_end_column'] ?? $highestColumn);
        for ($column = $dateStartColumn; $column <= $dateEndColumn; $column++) {
            $dayValue = $sheet->getCell([$column, $layout['day_header_row'] ?? $headerRow])->getValue();
            if (($layout['stop_after_days'] ?? false) && ! is_numeric($dayValue)) {
                break;
            }
            $monthInfo = $layout['type'] === 'payroll'
                ? collect($layout['months'])->filter(fn ($month) => $month['column'] <= $column)->last()
                : null;
            $date = $layout['type'] === 'topi'
                ? $layout['starts_on']->copy()->addDays($column - $dateStartColumn)
                : ($layout['type'] === 'payroll'
                ? $this->dateFromMonthlyHeader($sheet->getCell([$column, $layout['day_header_row']])->getValue(), $monthInfo['month'], $monthInfo['year'])
                : ($layout['type'] === 'monthly'
                ? $this->dateFromMonthlyHeader($sheet->getCell([$column, $layout['day_header_row']])->getValue(), $layout['month'], $layout['year'])
                : $this->dateFromCell($sheet->getCell([$column, $headerRow])->getValue())
                ));
            if (! $date) {
                $errors[] = 'Tidak dapat membaca tanggal pada file Excel di kolom '.Coordinate::stringFromColumnIndex($column).'.';

                continue;
            }
            if (isset($seenDates[$date->toDateString()])) {
                $errors[] = 'Terdapat tanggal duplikat pada header: '.$date->locale('id')->translatedFormat('d F Y').'.';

                continue;
            }
            $seenDates[$date->toDateString()] = true;
            $dates[] = ['column' => $column, 'date' => $date];
        }

        if (! $dates) {
            $errors[] = 'Tidak dapat membaca tanggal pada file Excel.';
        }
        for ($index = 1; $index < count($dates); $index++) {
            if (! $dates[$index]['date']->equalTo($dates[$index - 1]['date']->copy()->addDay())) {
                $errors[] = 'Tanggal pada header tidak berurutan.';
                break;
            }
        }

        $employeeNumbers = [];
        $rows = [];
        $codes = config('attendance.import_codes', []);
        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            if (isset($layout['row_identity_column'])
                && trim((string) $sheet->getCell([$layout['row_identity_column'], $row])->getFormattedValue()) === '') {
                continue;
            }
            $name = trim((string) $sheet->getCell([$nameColumn, $row])->getFormattedValue());
            $number = trim((string) $sheet->getCell([$numberColumn, $row])->getFormattedValue());
            if ($name === '' && $number === '') {
                continue;
            }
            if ($name === '') {
                $errors[] = 'Nama karyawan pada baris '.$row.' belum diisi.';

                continue;
            }
            if ($number === '' && ! ($layout['number_optional'] ?? false)) {
                $errors[] = 'NIK karyawan pada baris '.$row.' belum diisi.';

                continue;
            }
            $key = $number !== '' ? 'nik:'.$this->normalizedEmployeeNumber($number) : 'name:'.$this->normalizedName($name);
            if (isset($employeeNumbers[$key])) {
                $identity = $number !== '' ? 'NIK '.$number : 'Nama '.$name;
                $errors[] = $identity.' duplikat pada baris '.$employeeNumbers[$key].' dan '.$row.'.';

                continue;
            }
            $employeeNumbers[$key] = $row;
            $values = [];
            foreach ($dates as $column) {
                $raw = trim((string) $sheet->getCell([$column['column'], $row])->getFormattedValue());
                if ($raw === '') {
                    continue;
                }
                if (in_array(mb_strtoupper($raw), $layout['ignored_raw_codes'] ?? [], true)) {
                    continue;
                }
                $normalized = $codes[mb_strtoupper($raw)] ?? null;
                if (! $normalized) {
                    $errors[] = 'Kode absensi "'.$raw.'" untuk NIK '.$number.' pada '.$column['date']->locale('id')->translatedFormat('d F Y').' tidak dikenali.';

                    continue;
                }
                if (in_array($normalized, $layout['ignored_codes'] ?? [], true)) {
                    continue;
                }
                $values[$column['column']] = $normalized;
            }
            $rows[] = ['key' => $key, 'name' => $name, 'number' => $number, 'values' => $values];
        }

        if (! $rows && ! $errors) {
            $errors[] = 'Tidak ada data karyawan pada file Excel.';
        }
        if ($errors) {
            Log::warning('Validasi impor absensi gagal.', [
                'header_row' => $headerRow,
                'layout' => $layout['type'],
                'month_headers' => $layout['months'] ?? ($layout['month_name'] ?? null),
                'day_columns' => collect($dates)->map(fn ($item) => ['column' => $item['column'], 'date' => $item['date']->toDateString()])->all(),
                'errors' => $errors,
            ]);
            throw ValidationException::withMessages(['file' => $errors]);
        }

        return compact('dates', 'rows');
    }

    private function normalizedEmployeeNumber(string $number): string
    {
        return mb_strtolower(trim($number));
    }

    private function normalizedName(string $name): string
    {
        return mb_strtolower(preg_replace('/[^\pL\pN]+/u', '', trim($name)));
    }

    private function employeeByUniqueName(string $name): ?Employee
    {
        $normalized = $this->normalizedName($name);
        $employees = Employee::query()->get()->filter(
            fn (Employee $employee) => $this->normalizedName($employee->name) === $normalized
        )->take(2);

        return $employees->count() === 1 ? $employees->first() : null;
    }

    private function worksheetForPeriod($workbook, ?Carbon $periodStart, ?Carbon $periodEnd)
    {
        if (! $periodStart || ! $periodEnd) {
            return $workbook->getActiveSheet();
        }

        $fallback = null;
        foreach ($workbook->getAllSheets() as $sheet) {
            try {
                $layout = $this->findHeaderLayout($sheet);
            } catch (ValidationException) {
                continue;
            }
            if ($layout['type'] !== 'payroll') {
                $fallback ??= $sheet;
                continue;
            }
            $months = $layout['months'];
            $first = $months[0];
            $last = $months[count($months) - 1];
            if ($first['month'] === $periodStart->month && $first['year'] === $periodStart->year
                && $last['month'] === $periodEnd->month && $last['year'] === $periodEnd->year) {
                return $sheet;
            }
        }

        if ($fallback && count($workbook->getAllSheets()) === 1) {
            return $fallback;
        }

        throw ValidationException::withMessages([
            'file' => 'Tidak ditemukan sheet untuk periode '.$periodStart->locale('id')->translatedFormat('d M Y').' sampai '.$periodEnd->locale('id')->translatedFormat('d M Y').'.',
        ]);
    }

    private function findHeaderLayout($sheet): array
    {
        for ($row = 1; $row <= min(20, $sheet->getHighestDataRow()); $row++) {
            $topiSequence = $this->normalizeHeader($sheet->getCell([1, $row])->getFormattedValue());
            $topiId = $this->normalizeHeader($sheet->getCell([2, $row])->getFormattedValue());
            $topiName = $this->normalizeHeader($sheet->getCell([3, $row])->getFormattedValue());
            if ($topiSequence === 'no' && $topiId === 'id' && $topiName === 'nama lengkap') {
                $startsOn = $this->dateFromCell($sheet->getCell([6, $row + 1])->getValue());
                if (! $startsOn) {
                    throw ValidationException::withMessages(['file' => 'Tanggal awal pada format rekap TOPI tidak dapat dibaca.']);
                }

                return ['type' => 'topi', 'employee_header_row' => $row + 1, 'day_header_row' => $row + 1,
                    'name_column' => 3, 'number_column' => 2, 'date_start_column' => 6, 'date_end_column' => 36,
                    'row_identity_column' => 1, 'number_optional' => true, 'ignored_raw_codes' => ['OFF'], 'starts_on' => $startsOn];
            }
            $sequence = $this->normalizeHeader($sheet->getCell([2, $row])->getFormattedValue());
            $coordinatorId = $this->normalizeHeader($sheet->getCell([3, $row])->getFormattedValue());
            $coordinatorName = $this->normalizeHeader($sheet->getCell([4, $row])->getFormattedValue());
            if ($sequence === 'no' && $coordinatorId === 'id' && in_array($coordinatorName, ['nama lengkap', 'nama karyawan'], true)) {
                $months = [];
                $highest = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
                for ($column = 6; $column <= $highest; $column++) {
                    if ($month = $this->monthFromTitle($sheet->getCell([$column, $row])->getFormattedValue())) {
                        $months[] = ['column' => $column, ...$month];
                    }
                }
                if (count($months) < 2) {
                    throw ValidationException::withMessages(['file' => 'Header dua bulan pada format absensi koordinator tidak dapat dibaca.']);
                }

                return ['type' => 'payroll', 'employee_header_row' => $row + 1, 'day_header_row' => $row + 1,
                    'name_column' => 4, 'number_column' => 3, 'date_start_column' => 6, 'number_optional' => true,
                    'stop_after_days' => true, 'row_identity_column' => 2, 'ignored_codes' => ['L'], 'months' => $months];
            }
            $name = $this->normalizeHeader($sheet->getCell([1, $row])->getFormattedValue());
            $id = $this->normalizeHeader($sheet->getCell([2, $row])->getFormattedValue());
            if (in_array($name, ['employee name', 'name'], true) && in_array($id, ['employee id', 'employee id (nik)', 'nik', 'employee number'], true)) {
                return ['type' => 'dated', 'employee_header_row' => $row];
            }

            if (in_array($name, ['nama karyawan', 'nama pegawai'], true) && in_array($id, ['nik karyawan', 'nik pegawai'], true)) {
                $months = [];
                $highest = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
                for ($column = 3; $column <= $highest; $column++) {
                    if ($month = $this->monthFromTitle($sheet->getCell([$column, $row])->getFormattedValue())) {
                        $months[] = ['column' => $column, ...$month];
                    }
                }
                if (count($months) === 2) {
                    return ['type' => 'payroll', 'employee_header_row' => $row, 'day_header_row' => $row + 1, 'months' => $months];
                }
                if (count($months) === 0) {
                    throw ValidationException::withMessages(['file' => 'Header bulan pertama tidak ditemukan.']);
                }
                if (count($months) === 1) {
                    throw ValidationException::withMessages(['file' => 'Header bulan kedua tidak ditemukan.']);
                }
                $month = $this->monthFromTitle($sheet->getCell([3, $row])->getFormattedValue());
                if (! $month) {
                    throw ValidationException::withMessages(['file' => 'Invalid template: cell C'.$row.' must contain a month and year, for example "Juni 2026".']);
                }

                return [
                    'type' => 'monthly',
                    'employee_header_row' => $row,
                    'day_header_row' => $row + 1,
                    ...$month,
                ];
            }
        }
        throw ValidationException::withMessages(['file' => 'Tidak dapat membaca header Nama Karyawan dan NIK pada file Excel.']);
    }

    private function normalizeHeader(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function monthFromTitle(mixed $value): ?array
    {
        if (is_numeric($value)) {
            try {
                $date = Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value));

                return ['month' => $date->month, 'year' => $date->year, 'month_name' => $date->format('M-Y')];
            } catch (\Throwable) {
                return null;
            }
        }
        $title = mb_strtolower(trim((string) $value));
        if (! preg_match('/^([[:alpha:]]+)[\s-]+(\d{2}|\d{4})$/u', $title, $matches)) {
            return null;
        }

        $months = [
            'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6,
            'juli' => 7, 'agustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
            'january' => 1, 'february' => 2, 'march' => 3, 'may' => 5, 'june' => 6, 'july' => 7,
            'august' => 8, 'october' => 10, 'december' => 12,
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7,
            'agu' => 8, 'agt' => 8, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'okt' => 10, 'nov' => 11, 'dec' => 12, 'des' => 12,
        ];
        $month = $months[$matches[1]] ?? null;

        $year = (int) $matches[2];
        if ($year < 100) {
            $year += 2000;
        }

        return $month ? ['month' => $month, 'year' => $year, 'month_name' => trim((string) $value)] : null;
    }

    private function dateFromMonthlyHeader(mixed $value, int $month, int $year): ?Carbon
    {
        if (! is_numeric($value) || (int) $value != $value) {
            return null;
        }

        $day = (int) $value;
        if ($day < 1 || $day > Carbon::create($year, $month, 1)->daysInMonth) {
            return null;
        }

        return Carbon::create($year, $month, $day)->startOfDay();
    }

    private function dateFromCell(mixed $value): ?Carbon
    {
        try {
            if (is_numeric($value)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
            }
            if ($value instanceof DateTimeInterface) {
                return Carbon::instance($value)->startOfDay();
            }

            return is_string($value) && trim($value) !== '' ? Carbon::parse($value)->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
