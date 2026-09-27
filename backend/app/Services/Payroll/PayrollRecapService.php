<?php

namespace App\Services\Payroll;

use App\Models\AttendancePeriod;
use App\Models\Employee;
use App\Models\PayrollCompletion;
use App\Models\PayrollSalarySlipSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class PayrollRecapService
{
    private const LAST_COLUMN = 'BG';

    public function __construct(private readonly PayrollRecapCategoryService $categories) {}

    public function summary(AttendancePeriod $period, array $filters): array
    {
        [, $snapshots] = $this->completedSnapshots($period, $filters);

        return [
            'pt' => $filters['pt'],
            'employee_count' => $snapshots->count(),
            'total' => round((float) $snapshots->sum(fn ($snapshot) => data_get($snapshot->payload, 'totals.take_home_pay', 0)), 2),
        ];
    }

    public function create(AttendancePeriod $period, array $filters): array
    {
        $pt = $filters['pt'];
        [, $snapshots] = $this->completedSnapshots($period, $filters);
        $total = round((float) $snapshots->sum(fn ($snapshot) => data_get($snapshot->payload, 'totals.take_home_pay', 0)), 2);
        $employeeCount = $snapshots->count();
        $transferAmount = round((float) $filters['transfer_amount'], 2);
        $cashAmount = round((float) $filters['cash_amount'], 2);
        $transferCount = (int) $filters['transfer_count'];
        $cashCount = (int) $filters['cash_count'];
        if ($transferCount > $employeeCount) {
            throw ValidationException::withMessages([
                'transfer_count' => 'Jumlah karyawan transfer tidak boleh melebihi total '.$employeeCount.' karyawan.',
            ]);
        }
        if ($cashCount > $employeeCount) {
            throw ValidationException::withMessages([
                'cash_count' => 'Jumlah karyawan tunai tidak boleh melebihi total '.$employeeCount.' karyawan.',
            ]);
        }
        if (($transferCount + $cashCount) !== $employeeCount) {
            throw ValidationException::withMessages([
                'transfer_count' => 'Jumlah karyawan transfer dan tunai harus sama dengan total '.$employeeCount.' karyawan.',
            ]);
        }
        if ($transferAmount > $total) {
            throw ValidationException::withMessages([
                'transfer_amount' => 'Jumlah transfer tidak boleh melebihi total payroll Rp'.number_format($total, 0, ',', '.').'.',
            ]);
        }
        if ($cashAmount > $total) {
            throw ValidationException::withMessages([
                'cash_amount' => 'Jumlah tunai tidak boleh melebihi total payroll Rp'.number_format($total, 0, ',', '.').'.',
            ]);
        }
        if (($transferAmount + $cashAmount) > $total + 0.01) {
            throw ValidationException::withMessages([
                'transfer_amount' => 'Jumlah transfer dan tunai tidak boleh melebihi total payroll Rp'.number_format($total, 0, ',', '.').'.',
            ]);
        }
        if (($transferAmount + $cashAmount) < $total - 0.01) {
            throw ValidationException::withMessages([
                'transfer_amount' => 'Jumlah transfer dan tunai harus sama dengan total payroll Rp'.number_format($total, 0, ',', '.').'.',
            ]);
        }

        $profiles = Employee::withTrashed()
            ->with('payrollBaselines')
            ->whereIn('employee_number', $snapshots->pluck('employee_number')->unique())
            ->get()
            ->keyBy('employee_number');

        $templatePath = config('payroll_recap.template_path');
        if (! is_string($templatePath) || ! is_file($templatePath)) {
            throw new RuntimeException('Template Rekap Payroll tidak tersedia.');
        }

        $spreadsheet = IOFactory::load($templatePath);
        $formulaValuesByWorksheet = $this->fillWorkbook(
            $spreadsheet,
            $period,
            $pt,
            $snapshots,
            $profiles,
            $transferAmount,
            $cashAmount,
            $transferCount,
            $cashCount,
            now('Asia/Jakarta')
        );

        $temporaryRoot = storage_path('app/private/temp/payroll-recaps');
        File::ensureDirectoryExists($temporaryRoot);
        $outputPath = $temporaryRoot.DIRECTORY_SEPARATOR.Str::uuid().'.xlsx';

        try {
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->setForceFullCalc(true);
            $writer->save($outputPath);
            foreach ($formulaValuesByWorksheet as $worksheetPath => $formulaValues) {
                $this->writeFormulaCachedValues($outputPath, $worksheetPath, $formulaValues);
            }
        } catch (\Throwable $exception) {
            File::delete($outputPath);
            throw $exception;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return [
            'path' => $outputPath,
            'name' => $this->fileName($period, $pt),
        ];
    }

    private function completedSnapshots(AttendancePeriod $period, array $filters): array
    {
        $pt = $filters['pt'];
        $completion = PayrollCompletion::query()
            ->where('attendance_period_id', $period->id)
            ->where('pt', $pt)
            ->first();
        if (! $completion) {
            throw ValidationException::withMessages([
                'pt' => 'Payroll harus diselesaikan terlebih dahulu sebelum Rekap Payroll dapat diunduh.',
            ]);
        }

        $snapshots = PayrollSalarySlipSnapshot::query()
            ->where('attendance_period_id', $period->id)
            ->where('pt', $pt)
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(fn ($nested) => $nested
                    ->whereLike('employee_name', '%'.$search.'%')
                    ->orWhereLike('employee_number', '%'.$search.'%'));
            })
            ->orderBy('employee_name')
            ->get();
        if ($snapshots->isEmpty()) {
            throw ValidationException::withMessages([
                'search' => 'Tidak ada data payroll final yang sesuai dengan filter saat ini.',
            ]);
        }

        return [$completion, $snapshots];
    }

    /** @return array<string, array<string, float>> */
    private function fillWorkbook(
        Spreadsheet $spreadsheet,
        AttendancePeriod $period,
        string $pt,
        Collection $snapshots,
        Collection $profiles,
        float $transferAmount,
        float $cashAmount,
        int $transferCount,
        int $cashCount,
        \DateTimeInterface $generatedAt
    ): array {
        $mainSheet = $spreadsheet->getSheet(0);
        // The source workbook has no manual-deduction column. Insert it immediately
        // before TTL Potongan so every deduction remains visible and editable.
        $mainSheet->insertNewColumnBefore('AW', 1);
        $groups = $this->categories->group($snapshots, $pt);
        $companyName = config('employees.pt_names.'.$pt, $pt);
        $periodName = $this->monthName((int) $period->month).' '.(int) $period->year;
        $sheetName = $this->monthName((int) $period->month);

        $mainSheet->setTitle($sheetName);
        $mainSheet->setCellValue('AG1', $companyName);
        $mainSheet->setCellValue('AG2', $periodName);
        $mainSheet->setCellValue('AG3', $this->periodRange($period));
        $mainSheet->setCellValue('AG4', '-');
        $this->translateMainHeaders($mainSheet);
        foreach (range('C', 'F') as $column) {
            $mainSheet->getColumnDimension($column)->setVisible(false);
        }
        $mainSheet->getColumnDimension('AM')->setVisible(false);
        $mainSheet->getColumnDimension('AZ')->setVisible(false);
        $mainSheet->getColumnDimension('BC')->setVisible(false);
        $mainSheet->setCellValue('AM7', null);

        $styles = [
            'detail' => $this->captureRowStyle($mainSheet, 9),
            'subtotal' => $this->captureRowStyle($mainSheet, 14),
            'grand_total' => $this->captureRowStyle($mainSheet, 21),
        ];

        $this->clearMainData($mainSheet);

        $row = 9;
        $number = 1;
        $subtotalRows = [];
        $mainFormulaValues = [];
        $grandTotals = array_fill_keys($this->totalColumns(), 0.0);
        foreach ($groups as $groupIndex => $group) {
            $firstEmployeeRow = $row;
            $subtotalValues = array_fill_keys($this->totalColumns(), 0.0);
            foreach ($group['snapshots'] as $snapshot) {
                $this->applyRowStyle($mainSheet, $row, $styles['detail']);
                $rowFormulaValues = $this->fillEmployeeRow(
                    $mainSheet,
                    $row,
                    $number++,
                    $snapshot->payload,
                    $profiles->get($snapshot->employee_number)
                );
                foreach ($rowFormulaValues as $column => $value) {
                    $mainFormulaValues[$column.$row] = $value;
                }
                foreach ($this->totalColumns() as $column) {
                    $subtotalValues[$column] += $rowFormulaValues[$column]
                        ?? (float) $mainSheet->getCell($column.$row)->getValue();
                }
                $row++;
            }

            $lastEmployeeRow = $row - 1;
            $this->applyRowStyle($mainSheet, $row, $styles['subtotal']);
            $displayName = $group['name'] === config('payroll_recap.fallback_category', 'Belum Dipetakan')
                ? 'Operation'
                : $group['name'];
            $mainSheet->setCellValue('B'.$row, ($groupIndex + 1).'. '.$displayName);
            $this->setSubtotalFormulas($mainSheet, $row, $firstEmployeeRow, $lastEmployeeRow);
            foreach ($subtotalValues as $column => $value) {
                $value = round($value, 2);
                $mainFormulaValues[$column.$row] = $value;
                $mainSheet->getCell($column.$row)->setCalculatedValue($value);
                $grandTotals[$column] += $value;
            }
            $subtotalRows[] = $row;
            $row++;
        }

        $grandTotalRow = $row;
        $this->applyRowStyle($mainSheet, $grandTotalRow, $styles['grand_total']);
        $mainSheet->setCellValue('B'.$grandTotalRow, 'Total Keseluruhan :');
        $this->setGrandTotalFormulas($mainSheet, $grandTotalRow, $subtotalRows);
        foreach ($grandTotals as $column => $value) {
            $value = round($value, 2);
            $grandTotals[$column] = $value;
            $mainFormulaValues[$column.$grandTotalRow] = $value;
            $mainSheet->getCell($column.$grandTotalRow)->setCalculatedValue($value);
        }
        $mainSheet->getPageSetup()->setPrintArea('A1:'.self::LAST_COLUMN.$grandTotalRow);

        $slipSheet = $spreadsheet->getSheetByName('New slip') ?? $spreadsheet->getSheet(1);
        $slipSheet->setTitle('Slip Gaji');
        $this->fillSalarySlipSheet($slipSheet, $sheetName, $companyName, $period, $grandTotalRow, $snapshots->count());

        $summarySheet = $spreadsheet->getSheetByName('REKAP') ?? $spreadsheet->getSheet(2);
        $summaryFormulaValues = $this->fillSummarySheet(
            $summarySheet,
            $sheetName,
            $companyName,
            $pt,
            $period,
            $grandTotalRow,
            $transferAmount,
            $cashAmount,
            $transferCount,
            $cashCount,
            $snapshots->count(),
            $grandTotals,
            $generatedAt
        );

        $this->removeWorkbookComments($spreadsheet);
        $spreadsheet->setActiveSheetIndex(0);

        return [
            'xl/worksheets/sheet'.($spreadsheet->getIndex($mainSheet) + 1).'.xml' => $mainFormulaValues,
            'xl/worksheets/sheet'.($spreadsheet->getIndex($summarySheet) + 1).'.xml' => $summaryFormulaValues,
        ];
    }

    private function fillEmployeeRow(
        Worksheet $worksheet,
        int $row,
        int $number,
        array $payload,
        ?Employee $profile
    ): array {
        $employee = $payload['employee'] ?? [];
        $income = $payload['income'] ?? [];
        $deductions = $payload['deductions'] ?? [];
        $attendance = $payload['attendance'] ?? [];
        $totals = $payload['totals'] ?? [];
        $baseline = $payload['baseline'] ?? [];
        $profileBaseline = $profile?->payrollBaselineForPt($payload['pt'] ?? null);
        $manualIncome = (float) ($totals['manual_income'] ?? 0);
        $gajiPokok = (float) ($income['gaji_pokok'] ?? 0);
        $uangHarian = (float) ($income['uang_harian'] ?? 0);
        $insentif = (float) ($income['insentif'] ?? 0);
        $tunjanganAntarCabang = (float) ($income['tunj_antar_cabang'] ?? 0);
        $tunjanganKomunikasi = (float) ($income['tunj_komunikasi'] ?? 0);
        $tunjanganKost = (float) ($income['tunj_kost'] ?? 0);
        $tunjanganJabatan = (float) ($income['tunj_jabatan'] ?? 0);
        $lembur = (float) ($income['overtime_amount'] ?? 0);
        $salary = round(
            $gajiPokok
            + $uangHarian
            + $insentif
            + $tunjanganAntarCabang
            + $tunjanganKomunikasi
            + $tunjanganKost
            + $tunjanganJabatan
            + $manualIncome
            + $lembur,
            2
        );

        if ($number === 1) {
            $worksheet->setCellValue('A'.$row, 1);
        } else {
            $worksheet->setCellValue('A'.$row, '=MAX($A$8:A'.($row - 1).')+1');
            $worksheet->getCell('A'.$row)->setCalculatedValue($number);
        }
        $worksheet->setCellValue('B'.$row, $employee['name'] ?? '-');
        $this->setText($worksheet, 'G'.$row, (string) ($employee['number'] ?? $profile?->employee_number ?? ''));
        $worksheet->setCellValue('H'.$row, $employee['family_status'] ?? '-');
        $worksheet->setCellValue('L'.$row, (int) ($attendance['cuti'] ?? 0));
        $worksheet->setCellValue('M'.$row, (int) ($attendance['sakit'] ?? 0));
        $worksheet->setCellValue('N'.$row, (int) ($attendance['izin'] ?? 0));
        $worksheet->setCellValue('O'.$row, (int) ($attendance['alpha'] ?? 0));
        $worksheet->setCellValue('P'.$row, (int) ($attendance['hadir'] ?? 0));
        $worksheet->setCellValue('Q'.$row, (float) ($baseline['uang_harian'] ?? $profileBaseline?->uang_harian ?? 0));
        $worksheet->setCellValue('S'.$row, (float) ($income['overtime_hours'] ?? 0));
        $worksheet->setCellValue('V'.$row, (int) ($attendance['alpha'] ?? 0));
        $alphaDays = (int) ($attendance['alpha'] ?? 0);
        $alphaRate = (float) ($baseline['potongan_alpha'] ?? 0);
        if ($alphaRate <= 0) {
            $alphaRate = (float) ($profileBaseline?->potongan_alpha ?? 0);
        }
        if ($alphaRate <= 0 && $alphaDays > 0) {
            $alphaRate = round((float) ($deductions['potongan_alpha'] ?? 0) / $alphaDays, 2);
        }
        $alphaDeduction = round($alphaDays * $alphaRate, 2);
        $absenceDeduction = ($deductions['potongan_absensi_is_alpha'] ?? false)
            ? round((float) ($deductions['potongan_terlambat'] ?? 0), 2)
            : round((float) ($deductions['potongan_absensi'] ?? 0), 2);
        $worksheet->setCellValue('W'.$row, '=V'.$row.'*'.$this->formulaNumber($alphaRate));
        $worksheet->getCell('W'.$row)->setCalculatedValue($alphaDeduction);

        $worksheet->setCellValue('X'.$row, $gajiPokok);
        $dailyFormulaBase = (int) ($attendance['hadir'] ?? 0) * (float) ($baseline['uang_harian'] ?? $profileBaseline?->uang_harian ?? 0);
        $worksheet->setCellValue('Y'.$row, '=P'.$row.'*Q'.$row.$this->formulaAdjustment($uangHarian - $dailyFormulaBase));
        $worksheet->getCell('Y'.$row)->setCalculatedValue($uangHarian);
        $incentiveRate = $gajiPokok != 0.0 ? $insentif / $gajiPokok : 0.0;
        $worksheet->setCellValue('Z'.$row, '=X'.$row.'*'.$this->formulaNumber($incentiveRate).($gajiPokok == 0.0 ? $this->formulaAdjustment($insentif) : ''));
        $worksheet->getCell('Z'.$row)->setCalculatedValue($insentif);
        $worksheet->setCellValue('AA'.$row, $tunjanganAntarCabang);
        $worksheet->setCellValue('AB'.$row, $tunjanganKomunikasi);
        $worksheet->setCellValue('AC'.$row, $tunjanganKost);
        $worksheet->setCellValue('AD'.$row, $tunjanganJabatan);
        $worksheet->setCellValue('AE'.$row, $manualIncome);
        $overtimeHours = (float) ($income['overtime_hours'] ?? 0);
        $overtimeRate = $overtimeHours != 0.0 ? $lembur / $overtimeHours : 0.0;
        $worksheet->setCellValue('AF'.$row, '=S'.$row.'*'.$this->formulaNumber($overtimeRate).($overtimeHours == 0.0 ? $this->formulaAdjustment($lembur) : ''));
        $worksheet->getCell('AF'.$row)->setCalculatedValue($lembur);
        $worksheet->setCellValue('AG'.$row, '=SUM(X'.$row.':AF'.$row.')');
        $worksheet->getCell('AG'.$row)->setCalculatedValue($salary);
        $bpjsTkBase = (float) ($baseline['dasar_perhitungan_bpjs_tk'] ?? $profileBaseline?->dasar_perhitungan_bpjs_tk ?? 0);
        $bpjsKesBase = (float) ($baseline['dasar_perhitungan_bpjs_kes'] ?? $profileBaseline?->dasar_perhitungan_bpjs_kes ?? 0);
        $jhtPensPt = (float) ($income['jht_pens_pt'] ?? 0);
        $jkkJkmPt = (float) ($income['jkk_jkm_pt'] ?? 0);
        $bpjsKesPt = (float) ($income['bpjs_kes_pt'] ?? 0);
        $tunjBpjs = (float) ($income['tunj_bpjs_beban_pt'] ?? 0);
        $potonganTk = (float) ($deductions['potongan_jht_pens'] ?? 0);
        $potonganKes = (float) ($deductions['potongan_bpjs_karyawan'] ?? 0);
        $this->setPercentageFormula($worksheet, 'AH'.$row, 'BD'.$row, $bpjsTkBase, $jhtPensPt);
        $this->setPercentageFormula($worksheet, 'AI'.$row, 'BD'.$row, $bpjsTkBase, $jkkJkmPt);
        $this->setPercentageFormula($worksheet, 'AJ'.$row, 'BE'.$row, $bpjsKesBase, $bpjsKesPt);
        $worksheet->setCellValue('AK'.$row, '=SUM(AH'.$row.':AJ'.$row.')'.$this->formulaAdjustment($tunjBpjs - $jhtPensPt - $jkkJkmPt - $bpjsKesPt));
        $worksheet->getCell('AK'.$row)->setCalculatedValue($tunjBpjs);
        $worksheet->setCellValue('AL'.$row, (float) ($income['thr'] ?? 0));
        $worksheet->setCellValue('AM'.$row, null);
        $pph21 = (float) ($income['tunj_pph21'] ?? 0);
        $grossI = (float) ($totals['total_income'] ?? 0);
        $grossII = (float) ($totals['gross_ii'] ?? 0) + $manualIncome;
        $attendanceTotal = round($grossI - $grossII - $potonganTk, 4);
        $pphBaseFormula = '(AG'.$row.'+AK'.$row.'+AL'.$row.'+AM'.$row.'-AP'.$row.'-AQ'.$row.')';
        $isFinalTaxPeriod = (int) ($payload['period']['month'] ?? 0) === 12;
        $pphRate = $grossII != 0.0 ? $pph21 / $grossII : 0.0;
        $pphFormula = $isFinalTaxPeriod || $pphRate <= 0.0 || $pphRate >= 1.0
            ? '=BG'.$row
            : '='.$pphBaseFormula.'*'.$this->formulaNumber($pphRate).'/(1-'.$this->formulaNumber($pphRate).')';
        $worksheet->setCellValue('AN'.$row, $pphFormula);
        $worksheet->getCell('AN'.$row)->setCalculatedValue($pph21);
        $worksheet->setCellValue('AO'.$row, '=SUM(AK'.$row.':AN'.$row.')+AG'.$row);
        $worksheet->getCell('AO'.$row)->setCalculatedValue($grossI);
        $worksheet->setCellValue('AP'.$row, '=W'.$row.$this->formulaAdjustment($attendanceTotal - $alphaDeduction));
        $worksheet->getCell('AP'.$row)->setCalculatedValue($attendanceTotal);
        $this->setPercentageFormula($worksheet, 'AQ'.$row, 'BD'.$row, $bpjsTkBase, $potonganTk);
        $worksheet->setCellValue('AR'.$row, '=AO'.$row.'-AP'.$row.'-AQ'.$row);
        $worksheet->getCell('AR'.$row)->setCalculatedValue($grossII);
        $worksheet->setCellValue('AS'.$row, (float) ($deductions['potongan_pinjaman'] ?? 0));
        $potonganTunjBpjs = (float) ($deductions['potongan_tunj_pt'] ?? 0);
        $worksheet->setCellValue('AT'.$row, '=AK'.$row.$this->formulaAdjustment($potonganTunjBpjs - $tunjBpjs));
        $worksheet->getCell('AT'.$row)->setCalculatedValue($potonganTunjBpjs);
        $this->setPercentageFormula($worksheet, 'AU'.$row, 'BE'.$row, $bpjsKesBase, $potonganKes);
        $potonganPph21 = (float) ($deductions['potongan_tunj_pph21'] ?? 0);
        $worksheet->setCellValue('AV'.$row, '=AN'.$row.$this->formulaAdjustment($potonganPph21 - $pph21));
        $worksheet->getCell('AV'.$row)->setCalculatedValue($potonganPph21);
        $expectedNett = round((float) ($totals['take_home_pay'] ?? 0), 2);
        $fourNamedDeductions = (float) ($deductions['potongan_pinjaman'] ?? 0) + $potonganTunjBpjs + $potonganKes + $potonganPph21;
        // Older snapshots can contain an unitemized/manual remainder in total_deduction.
        // Derive the displayed manual deduction from the finalized Nett so reopening
        // and recalculating the workbook can never change the approved payroll result.
        $displayedManualDeduction = round($grossII - $expectedNett - $fourNamedDeductions, 4);
        if ($displayedManualDeduction < -0.01) {
            throw new RuntimeException('Potongan manual menghasilkan nilai negatif untuk '.($employee['name'] ?? 'karyawan').'.');
        }
        $worksheet->setCellValue('AW'.$row, max(0, $displayedManualDeduction));
        $ttlPotongan = round($fourNamedDeductions + max(0, $displayedManualDeduction), 4);
        $nett = round($grossII - $ttlPotongan, 2);
        if (abs($nett - $expectedNett) > 0.01) {
            throw new RuntimeException('Formula Nett tidak sesuai dengan payroll final untuk '.($employee['name'] ?? 'karyawan').'.');
        }
        $worksheet->setCellValue('AX'.$row, '=SUM(AS'.$row.':AW'.$row.')');
        $worksheet->getCell('AX'.$row)->setCalculatedValue($ttlPotongan);
        $worksheet->setCellValue('AY'.$row, '=AR'.$row.'-AX'.$row);
        $worksheet->getCell('AY'.$row)->setCalculatedValue($nett);
        $worksheet->setCellValue('AZ'.$row, $employee['title'] ?? '-');
        $worksheet->setCellValue('BA'.$row, '=AU'.$row.'+AJ'.$row);
        $worksheet->getCell('BA'.$row)->setCalculatedValue($potonganKes + $bpjsKesPt);
        $worksheet->setCellValue('BB'.$row, '=AT'.$row.'+AQ'.$row.'-AJ'.$row);
        $worksheet->getCell('BB'.$row)->setCalculatedValue($potonganTunjBpjs + $potonganTk - $bpjsKesPt);
        $worksheet->setCellValue('BC'.$row, $employee['location'] ?? '-');
        $worksheet->setCellValue('BD'.$row, $bpjsTkBase);
        $worksheet->setCellValue('BE'.$row, $bpjsKesBase);
        $worksheet->setCellValue('BG'.$row, $pph21);

        $formulaValues = [
            'W' => $alphaDeduction,
            'Y' => $uangHarian,
            'Z' => $insentif,
            'AF' => $lembur,
            'AG' => $salary,
            'AH' => $jhtPensPt,
            'AI' => $jkkJkmPt,
            'AJ' => $bpjsKesPt,
            'AK' => $tunjBpjs,
            'AN' => $pph21,
            'AO' => $grossI,
            'AP' => $attendanceTotal,
            'AQ' => $potonganTk,
            'AR' => $grossII,
            'AT' => $potonganTunjBpjs,
            'AU' => $potonganKes,
            'AV' => $potonganPph21,
            'AX' => $ttlPotongan,
            'AY' => $nett,
            'BA' => $potonganKes + $bpjsKesPt,
            'BB' => $potonganTunjBpjs + $potonganTk - $bpjsKesPt,
        ];

        if ($number > 1) {
            $formulaValues['A'] = (float) $number;
        }

        return $formulaValues;
    }

    private function fillSalarySlipSheet(
        Worksheet $worksheet,
        string $mainSheetName,
        string $companyName,
        AttendancePeriod $period,
        int $grandTotalRow,
        int $employeeCount
    ): void {
        $range = $this->quotedSheet($mainSheetName).'!$A$9:$'.self::LAST_COLUMN.'$'.$grandTotalRow;
        $worksheet->setCellValue('B1', $companyName);
        $worksheet->setCellValue('B2', 'Slip Gaji');
        $worksheet->setCellValue('N1', $employeeCount);
        $worksheet->setCellValue('B4', 'Data Pribadi');
        $worksheet->setCellValue('B5', 'Nama Karyawan');
        $worksheet->setCellValue('J5', 'Periode Payroll');
        $worksheet->setCellValue('L5', $this->monthName((int) $period->month).' '.(int) $period->year);
        $worksheet->setCellValue('B6', 'Jabatan');
        $worksheet->setCellValue('J6', 'Periode Batas');
        $worksheet->setCellValue('L6', $this->periodRange($period));
        $worksheet->setCellValue('B7', 'Lokasi');
        $worksheet->setCellValue('J7', 'Sisa Cuti');
        $worksheet->setCellValue('B8', 'Status Keluarga');
        $worksheet->setCellValue('B10', 'A. Pendapatan Tetap :');
        $worksheet->setCellValue('H10', 'C. Potongan');
        $worksheet->setCellValue('D12', 'Gaji Pokok');
        $worksheet->setCellValue('B14', 'B. Pendapatan Variabel Lain :');
        $worksheet->setCellValue('D18', 'Insentif Kerajinan');
        $worksheet->setCellValue('D19', 'Pendapatan Manual');
        $worksheet->setCellValue('J21', null);
        $worksheet->setCellValue('H29', 'D. Total Diterima');

        $lookups = [
            'F5' => 2, 'F6' => 52, 'F7' => 55, 'L7' => 9, 'F8' => 8,
            'F12' => 24, 'F15' => 38, 'F16' => 40, 'F17' => 25,
            'F18' => 26, 'F19' => 31, 'F20' => 27, 'F21' => 28,
            'F22' => 29, 'F23' => 30, 'F24' => 32, 'F25' => 37,
            'F26' => 38, 'L12' => 42, 'L15' => 46, 'L20' => 45,
        ];
        foreach ($lookups as $cell => $columnIndex) {
            $worksheet->setCellValue($cell, '=VLOOKUP($E$13,'.$range.','.$columnIndex.',FALSE)');
        }
        $worksheet->setCellValue('L16', '=VLOOKUP($E$13,'.$range.',43,FALSE)+VLOOKUP($E$13,'.$range.',47,FALSE)');
        $worksheet->setCellValue('L17', '=F16');
        $worksheet->setCellValue('L21', null);
        $worksheet->setCellValue('F29', '=SUM(F12:F28)');
        $worksheet->setCellValue('L29', '=F29-SUM(L12:L21)');
    }

    private function fillSummarySheet(
        Worksheet $worksheet,
        string $mainSheetName,
        string $companyName,
        string $pt,
        AttendancePeriod $period,
        int $grandTotalRow,
        float $transferTotal,
        float $cashTotal,
        int $transferCount,
        int $cashCount,
        int $employeeCount,
        array $grandTotals,
        \DateTimeInterface $generatedAt
    ): array {
        $sheet = $this->quotedSheet($mainSheetName);

        $this->clearSummaryAreas($worksheet);
        $worksheet->getStyle('A1:P40')->getFill()->setFillType(Fill::FILL_NONE);

        $worksheet->setCellValue('D5', $companyName.' ('.$pt.')');
        $worksheet->setCellValue('B7', 'REKAP GAJI : '.$this->monthName((int) $period->month).' '.(int) $period->year);
        $worksheet->setCellValue('B8', 'TANGGAL PEMBAYARAN : '.$this->formatDate($generatedAt));
        $worksheet->setCellValue('E12', 'NO.');
        $worksheet->setCellValue('F12', 'KETERANGAN');
        $worksheet->setCellValue('I12', 'RINCIAN');
        $worksheet->setCellValue('I13', 'Rp.');
        $worksheet->setCellValue('J12', 'JUMLAH');
        $worksheet->setCellValue('J13', 'Rp.');
        $worksheet->setCellValue('E15', 1);
        $worksheet->setCellValue('F15', 'PENDAPATAN BRUTO');
        $worksheet->setCellValue('E17', 2);
        $worksheet->setCellValue('F17', 'POTONGAN');
        $worksheet->setCellValue('H17', 'a. Pinjaman');
        $worksheet->setCellValue('H18', 'b. Jamsostek');
        $worksheet->setCellValue('H19', 'c. Pajak');
        $worksheet->setCellValue('E20', 3);
        $worksheet->setCellValue('F20', 'PENDAPATAN BERSIH');
        $worksheet->setCellValue('F22', 'RINCIAN PEMBAYARAN :');
        $worksheet->setCellValue('E24', 1);
        $worksheet->setCellValue('F24', 'Transfer melalui Bank');
        $worksheet->setCellValue('E25', 2);
        $worksheet->setCellValue('F25', 'Tunai');
        $worksheet->setCellValue('I15', '=COUNTA('.$sheet.'!A9:A'.($grandTotalRow - 1).')');
        $worksheet->setCellValue('J15', '='.$sheet.'!AO'.$grandTotalRow.'-'.$sheet.'!AP'.$grandTotalRow);
        $worksheet->setCellValue('I17', '='.$sheet.'!AS'.$grandTotalRow);
        $worksheet->setCellValue('I18', '='.$sheet.'!AQ'.$grandTotalRow.'+'.$sheet.'!AT'.$grandTotalRow.'+'.$sheet.'!AU'.$grandTotalRow);
        $worksheet->setCellValue('I19', '='.$sheet.'!AV'.$grandTotalRow);
        $worksheet->setCellValue('J18', '='.$sheet.'!AX'.$grandTotalRow.'+'.$sheet.'!AQ'.$grandTotalRow);
        $worksheet->setCellValue('J20', '='.$sheet.'!AY'.$grandTotalRow);
        $worksheet->setCellValue('H24', $transferCount);
        $worksheet->setCellValue('I24', $transferTotal);
        $worksheet->setCellValue('J24', null);
        $worksheet->setCellValue('H25', $cashCount);
        $worksheet->setCellValue('I25', $cashTotal);
        $worksheet->setCellValue('J25', '=SUM(I24:I25)');
        $worksheet->setCellValue('H26', '=I15-SUM(H24:H25)');
        $worksheet->setCellValue('I26', null);
        $worksheet->setCellValue('J26', '=J20-SUM(I24:I25)');

        $formulaValues = [
            'I15' => (float) $employeeCount,
            'J15' => round($grandTotals['AO'] - $grandTotals['AP'], 2),
            'I17' => round($grandTotals['AS'], 2),
            'I18' => round($grandTotals['AQ'] + $grandTotals['AT'] + $grandTotals['AU'], 2),
            'I19' => round($grandTotals['AV'], 2),
            'J18' => round($grandTotals['AX'] + $grandTotals['AQ'], 2),
            'J20' => round($grandTotals['AY'], 2),
            'J25' => round($transferTotal + $cashTotal, 2),
            'H26' => (float) ($employeeCount - $transferCount - $cashCount),
            'J26' => round($grandTotals['AY'] - $transferTotal - $cashTotal, 2),
        ];
        foreach ($formulaValues as $coordinate => $value) {
            $worksheet->getCell($coordinate)->setCalculatedValue($value);
        }

        $worksheet->setCellValue('D31', 'Jakarta, '.$this->formatDate($generatedAt));
        $worksheet->setCellValue('D33', 'Dibuat oleh :');
        $worksheet->setCellValue('H33', 'Diperiksa oleh :');
        $worksheet->setCellValue('J33', 'Diketahui oleh :');

        $worksheet->getStyle('D5')->getFont()->setBold(true);
        $worksheet->getStyle('B7:B8')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $worksheet->getStyle('E12:J26')->applyFromArray([
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders' => [
                'outline' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['argb' => 'FF000000'],
                ],
            ],
        ]);
        $worksheet->getStyle('E12:J13')->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $worksheet->getStyle('F20:J20')->getFont()->setBold(true);
        $worksheet->getStyle('F22:J22')->getFont()->setBold(true);
        $worksheet->getStyle('J18:J26')->getFont()->setBold(true);
        $worksheet->getStyle('I17:J26')->getNumberFormat()->setFormatCode('#,##0;[Red]-#,##0;-');
        $worksheet->getStyle('H24:H26')->getNumberFormat()->setFormatCode('#,##0;[Red]-#,##0;-');
        $worksheet->getStyle('J26')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF9BD5E0');
        $worksheet->getPageSetup()->setPrintArea('A1:P40');

        return $formulaValues;
    }

    private function clearSummaryAreas(Worksheet $worksheet): void
    {
        foreach (['N15:P19', 'L26:N31'] as $range) {
            [$start, $end] = Coordinate::rangeBoundaries($range);
            for ($row = $start[1]; $row <= $end[1]; $row++) {
                for ($column = $start[0]; $column <= $end[0]; $column++) {
                    $worksheet->setCellValue(Coordinate::stringFromColumnIndex($column).$row, null);
                }
            }

            $worksheet->duplicateStyle($worksheet->getParent()->getDefaultStyle(), $range);
        }
    }

    private function removeWorkbookComments(Spreadsheet $spreadsheet): void
    {
        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $worksheet->setComments([]);
        }
    }

    private function setSubtotalFormulas(Worksheet $worksheet, int $row, int $firstRow, int $lastRow): void
    {
        foreach ($this->totalColumns() as $column) {
            $worksheet->setCellValue($column.$row, '=SUM('.$column.$firstRow.':'.$column.$lastRow.')');
        }
    }

    private function setGrandTotalFormulas(Worksheet $worksheet, int $row, array $subtotalRows): void
    {
        foreach ($this->totalColumns() as $column) {
            $references = implode(',', array_map(fn (int $subtotalRow) => $column.$subtotalRow, $subtotalRows));
            $worksheet->setCellValue($column.$row, '=SUM('.$references.')');
        }
    }

    /** @return list<string> */
    private function totalColumns(): array
    {
        $columns = [];
        for ($columnIndex = 22; $columnIndex <= 59; $columnIndex++) {
            $column = Coordinate::stringFromColumnIndex($columnIndex);
            if (! in_array($column, ['AM', 'AZ', 'BC'], true)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /** @param array<string, float> $values */
    private function writeFormulaCachedValues(string $workbookPath, string $worksheetPath, array $values): void
    {
        if ($values === []) {
            return;
        }

        $archive = new \ZipArchive;
        if ($archive->open($workbookPath) !== true) {
            throw new RuntimeException('File Rekap Payroll tidak dapat dibuka untuk menyimpan hasil formula.');
        }

        try {
            $xml = $archive->getFromName($worksheetPath);
            if (! is_string($xml)) {
                throw new RuntimeException('Sheet Rekap Payroll tidak ditemukan.');
            }

            $document = new \DOMDocument;
            $document->preserveWhiteSpace = true;
            if (! $document->loadXML($xml)) {
                throw new RuntimeException('Sheet Rekap Payroll tidak dapat dibaca.');
            }

            $namespace = $document->documentElement?->namespaceURI;
            if (! is_string($namespace) || $namespace === '') {
                throw new RuntimeException('Format sheet Rekap Payroll tidak valid.');
            }

            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('x', $namespace);

            foreach ($values as $coordinate => $value) {
                $cell = $xpath->query('//x:c[@r="'.$coordinate.'"]')?->item(0);
                if (! $cell instanceof \DOMElement) {
                    throw new RuntimeException('Sel formula '.$coordinate.' tidak ditemukan.');
                }

                $cachedNodes = $xpath->query('./x:v', $cell);
                if ($cachedNodes !== false) {
                    foreach (iterator_to_array($cachedNodes) as $cachedNode) {
                        $cell->removeChild($cachedNode);
                    }
                }

                $cachedValue = $document->createElementNS($namespace, 'v', $this->formulaNumber($value));
                $formulaNode = $xpath->query('./x:f', $cell)?->item(0);
                if ($formulaNode?->nextSibling !== null) {
                    $cell->insertBefore($cachedValue, $formulaNode->nextSibling);
                } else {
                    $cell->appendChild($cachedValue);
                }
            }

            $updatedXml = $document->saveXML();
            if (! is_string($updatedXml) || $archive->addFromString($worksheetPath, $updatedXml) === false) {
                throw new RuntimeException('Hasil formula tidak dapat disimpan ke Rekap Payroll.');
            }
        } finally {
            $archive->close();
        }
    }

    private function translateMainHeaders(Worksheet $worksheet): void
    {
        foreach (range('C', 'F') as $column) {
            $worksheet->setCellValue($column.'7', null);
        }
        $worksheet->setCellValue('G7', 'ID KARYAWAN');
        $worksheet->setCellValue('V7', 'Total Alpha');
        $worksheet->setCellValue('W7', 'Potongan Alpha');
        $worksheet->setCellValue('AE7', 'Pendapatan Manual');
        $worksheet->setCellValue('AG7', 'GAJI');
        $worksheet->setCellValue('AO7', 'BRUTO 1');
        $worksheet->setCellValue('AP7', 'Potongan Absensi');
        $worksheet->setCellValue('AR7', 'BRUTO 2');
        $worksheet->setCellValue('AW7', 'Potongan Manual');
        $worksheet->setCellValue('AX7', 'TTL Potongan');
        $worksheet->setCellValue('AY7', 'BERSIH');
    }

    private function clearMainData(Worksheet $worksheet): void
    {
        $lastRow = max(22, $worksheet->getHighestDataRow());
        for ($row = 9; $row <= $lastRow; $row++) {
            for ($columnIndex = 1; $columnIndex <= 59; $columnIndex++) {
                $column = Coordinate::stringFromColumnIndex($columnIndex);
                $worksheet->setCellValue($column.$row, null);
            }
        }
    }

    private function captureRowStyle(Worksheet $worksheet, int $row): array
    {
        $styles = [];
        for ($columnIndex = 1; $columnIndex <= 59; $columnIndex++) {
            $column = Coordinate::stringFromColumnIndex($columnIndex);
            $styles[$column] = $worksheet->getStyle($column.$row)->exportArray();
        }

        return [
            'cells' => $styles,
            'height' => $worksheet->getRowDimension($row)->getRowHeight(),
        ];
    }

    private function applyRowStyle(Worksheet $worksheet, int $row, array $style): void
    {
        foreach ($style['cells'] as $column => $cellStyle) {
            $worksheet->getStyle($column.$row)->applyFromArray($cellStyle);
        }
        $worksheet->getRowDimension($row)->setRowHeight($style['height']);
    }

    private function setText(Worksheet $worksheet, string $coordinate, string $value): void
    {
        $worksheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
    }

    private function formulaNumber(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 12, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function formulaAdjustment(float $adjustment): string
    {
        if (abs($adjustment) < 0.00000001) {
            return '';
        }

        return $adjustment > 0
            ? '+'.$this->formulaNumber($adjustment)
            : $this->formulaNumber($adjustment);
    }

    private function setPercentageFormula(
        Worksheet $worksheet,
        string $coordinate,
        string $baseCoordinate,
        float $base,
        float $value
    ): void {
        $rate = $base != 0.0 ? $value / $base : 0.0;
        $formula = '='.$baseCoordinate.'*'.$this->formulaNumber($rate);
        if ($base == 0.0) {
            $formula .= $this->formulaAdjustment($value);
        }

        $worksheet->setCellValue($coordinate, $formula);
        $worksheet->getCell($coordinate)->setCalculatedValue($value);
    }

    private function periodRange(AttendancePeriod $period): string
    {
        if (! $period->starts_on || ! $period->ends_on) {
            return '-';
        }

        return $this->formatDate($period->starts_on).' s.d. '.$this->formatDate($period->ends_on);
    }

    private function formatDate(mixed $date): string
    {
        if (! $date) {
            return '-';
        }

        $value = $date instanceof \DateTimeInterface ? $date : new \DateTimeImmutable((string) $date);

        return $value->format('j').' '.$this->monthName((int) $value->format('n')).' '.$value->format('Y');
    }

    private function quotedSheet(string $sheetName): string
    {
        return "'".str_replace("'", "''", $sheetName)."'";
    }

    private function fileName(AttendancePeriod $period, string $pt): string
    {
        $fileName = sprintf(
            'Rekap Payroll - %s - %s %d.xlsx',
            $pt,
            $this->monthName((int) $period->month),
            (int) $period->year
        );
        $ascii = Str::ascii($fileName);
        $clean = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/', '-', $ascii);

        return trim((string) preg_replace('/\s+/', ' ', (string) $clean), ' .');
    }

    private function monthName(int $month): string
    {
        return [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ][$month] ?? (string) $month;
    }
}
