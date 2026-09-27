<?php

namespace App\Services\Payroll;

use App\Models\AttendancePeriod;
use App\Models\PayrollCalculation;
use App\Models\PayrollCompletion;
use App\Models\PayrollSalarySlipSnapshot;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

class PayrollSalarySlipService
{
    public function capture(PayrollCompletion $completion, PayrollCalculation $calculation): PayrollSalarySlipSnapshot
    {
        $calculation->loadMissing(['employee.payrollBaselines', 'manualAdjustments', 'payrollPeriod']);
        $employee = $calculation->employee;
        $period = $calculation->payrollPeriod;

        if (! $employee || ! $period) {
            throw new RuntimeException('Data karyawan atau periode payroll tidak tersedia untuk snapshot slip gaji.');
        }
        $baseline = $employee->payrollBaselines->firstWhere('pt', $calculation->pt ?? $completion->pt);
        $manualAdjustments = $calculation->manualAdjustments
            ->map(fn ($adjustment) => [
                'type' => $adjustment->type,
                'component_name' => $adjustment->component_name,
                'amount' => (float) $adjustment->amount,
            ])->values();
        $manualIncome = (float) $manualAdjustments->where('type', 'income')->sum('amount');
        $manualDeduction = (float) $manualAdjustments->where('type', 'deduction')->sum('amount');
        $grossI = round((float) $calculation->gross_i + $manualIncome, 2);
        $grossII = round((float) $calculation->gross_ii + $manualIncome, 2);
        $totalIncome = round((float) $calculation->gross_income + $manualIncome, 2);
        $totalDeduction = $calculation->cappedTotalDeduction($manualDeduction);

        $payload = [
            'company_name' => config('employees.pt_names.'.$completion->pt, $completion->pt),
            'pt' => $completion->pt,
            'employee' => [
                'number' => $employee->employee_number,
                'name' => $employee->name,
                'title' => $employee->position,
                'location' => $employee->department ?: $employee->branch,
                'family_status' => $employee->family_status,
                'bank_account_number' => $employee->bank_account_number,
                'join_date' => $employee->join_date?->toDateString(),
            ],
            'period' => [
                'month' => (int) $period->month,
                'year' => (int) $period->year,
                'starts_on' => $period->starts_on?->toDateString(),
                'ends_on' => $period->ends_on?->toDateString(),
            ],
            'income' => [
                'gaji_pokok' => (float) $calculation->gaji_pokok,
                'uang_harian' => (float) $calculation->uang_harian,
                'insentif' => (float) $calculation->insentif,
                'tunj_antar_cabang' => (float) $calculation->tunj_antar_cabang,
                'tunj_komunikasi' => (float) $calculation->tunj_komunikasi,
                'tunj_kost' => (float) $calculation->tunj_kost,
                'tunj_jabatan' => (float) $calculation->tunj_jabatan,
                'overtime_amount' => (float) $calculation->overtime_amount,
                'overtime_hours' => (float) $calculation->overtime_hours,
                'extra_time_amount' => (float) $calculation->extra_time_amount,
                'tunj_bpjs_beban_pt' => (float) $calculation->tunj_bpjs_beban_pt,
                'bpjs_kes_pt' => (float) $calculation->bpjs_kes_pt,
                'jkk_jkm_pt' => (float) $calculation->jkk_jkm_pt,
                'jht_pens_pt' => (float) $calculation->jht_pens_pt,
                'thr' => (float) $calculation->thr,
                'tunj_pph21' => (float) $calculation->tunj_pph21,
            ],
            'deductions' => [
                'potongan_absensi' => (float) $calculation->potongan_absensi,
                'potongan_alpha' => (float) $calculation->potongan_alpha,
                'potongan_terlambat' => (float) $calculation->potongan_terlambat,
                'potongan_absensi_is_alpha' => false,
                'potongan_tunj_pt' => (float) $calculation->potongan_tunj_pt,
                'potongan_jht_pens' => (float) $calculation->potongan_jht_pens,
                'potongan_bpjs_karyawan' => (float) $calculation->potongan_bpjs_karyawan,
                'potongan_tunj_pph21' => (float) $calculation->potongan_tunj_pph21,
                'potongan_pinjaman' => (float) $calculation->potongan_pinjaman,
                'potongan_lain' => (float) $calculation->potongan_lain,
            ],
            'attendance' => [
                'hadir' => (int) $calculation->hadir,
                'izin' => (int) $calculation->izin,
                'sakit' => (int) $calculation->sakit,
                'cuti' => (int) $calculation->cuti,
                'alpha' => (int) $calculation->alpha,
                'late_minutes' => (int) $calculation->late_minutes,
            ],
            'baseline' => [
                'uang_harian' => (float) ($baseline?->uang_harian ?? 0),
                'dasar_perhitungan_bpjs_tk' => (float) ($baseline?->dasar_perhitungan_bpjs_tk ?? 0),
                'dasar_perhitungan_bpjs_kes' => (float) ($baseline?->dasar_perhitungan_bpjs_kes ?? 0),
                'potongan_alpha' => (float) ($baseline?->potongan_alpha ?? 0),
            ],
            'manual_adjustments' => $manualAdjustments->all(),
            'totals' => [
                'gross_i' => $grossI,
                'gross_ii' => $grossII,
                'automatic_income' => (float) $calculation->gross_income,
                'automatic_deduction' => (float) $calculation->total_deduction,
                'manual_income' => $manualIncome,
                'manual_deduction' => $manualDeduction,
                'total_income' => $totalIncome,
                'total_deduction' => $totalDeduction,
                'take_home_pay' => round($totalIncome - $totalDeduction, 2),
            ],
        ];

        return PayrollSalarySlipSnapshot::updateOrCreate(
            ['payroll_calculation_id' => $calculation->id],
            [
                'payroll_completion_id' => $completion->id,
                'attendance_period_id' => $period->id,
                'pt' => $completion->pt,
                'employee_number' => $employee->employee_number,
                'employee_name' => $employee->name,
                'payload' => $payload,
            ]
        );
    }

    public function backfillExistingCompletions(): void
    {
        PayrollCompletion::query()->with('period')->chunkById(50, function (Collection $completions) {
            foreach ($completions as $completion) {
                PayrollCalculation::query()
                    ->where('payroll_period_id', $completion->attendance_period_id)
                    ->where('status', 'completed')
                    ->where(function ($query) use ($completion) {
                        $query->where('pt', $completion->pt)
                            ->orWhere(fn ($legacy) => $legacy->whereNull('pt')->whereHas('employee', fn ($employee) => $employee->where('branch', $completion->pt)));
                    })
                    ->with(['employee.payrollBaselines', 'manualAdjustments', 'payrollPeriod'])
                    ->chunkById(100, function (Collection $calculations) use ($completion) {
                        foreach ($calculations as $calculation) {
                            $this->capture($completion, $calculation);
                        }
                    });
            }
        });
    }

    public function createZip(AttendancePeriod $period, array $filters): array
    {
        $pt = $filters['pt'] ?? null;
        if ($pt) {
            $completed = PayrollCompletion::query()
                ->where('attendance_period_id', $period->id)
                ->where('pt', $pt)
                ->exists();
            if (! $completed) {
                throw ValidationException::withMessages(['pt' => 'Slip gaji hanya dapat diunduh setelah payroll PT berstatus Selesai.']);
            }
        } elseif (PayrollCalculation::query()->where('payroll_period_id', $period->id)->where('status', '!=', 'completed')->exists()) {
            throw ValidationException::withMessages(['pt' => 'Masih ada payroll berstatus Draf. Pilih PT yang sudah Selesai atau selesaikan seluruh payroll terlebih dahulu.']);
        }

        $query = PayrollSalarySlipSnapshot::query()
            ->where('attendance_period_id', $period->id)
            ->when($pt, fn ($builder, $value) => $builder->where('pt', $value))
            ->when($filters['search'] ?? null, function ($builder, $search) {
                $builder->where(fn ($nested) => $nested
                    ->whereLike('employee_name', '%'.$search.'%')
                    ->orWhereLike('employee_number', '%'.$search.'%'));
            })
            ->orderBy('employee_name');

        if (! $query->exists()) {
            throw ValidationException::withMessages(['search' => 'Tidak ada slip gaji final yang sesuai dengan filter saat ini.']);
        }

        $temporaryRoot = storage_path('app/private/temp/salary-slips');
        $temporaryDirectory = $temporaryRoot.DIRECTORY_SEPARATOR.Str::uuid();
        $zipPath = $temporaryRoot.DIRECTORY_SEPARATOR.Str::uuid().'.zip';
        File::ensureDirectoryExists($temporaryDirectory);

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            File::deleteDirectory($temporaryDirectory);
            throw new RuntimeException('File ZIP slip gaji tidak dapat dibuat.');
        }

        try {
            foreach ($query->cursor() as $snapshot) {
                $fileName = $this->salarySlipFileName($snapshot);
                $pdfPath = $temporaryDirectory.DIRECTORY_SEPARATOR.$fileName;
                File::put($pdfPath, $this->renderPdf($snapshot->payload));
                $zip->addFile($pdfPath, $fileName);
            }
            if (! $zip->close()) {
                throw new RuntimeException('File ZIP slip gaji tidak dapat diselesaikan.');
            }
        } catch (\Throwable $exception) {
            $zip->close();
            File::delete($zipPath);
            throw $exception;
        } finally {
            File::deleteDirectory($temporaryDirectory);
        }

        return [
            'path' => $zipPath,
            'name' => $this->zipFileName($period, $pt),
        ];
    }

    private function renderPdf(array $payload): string
    {
        $options = new Options;
        $options->set('defaultFont', 'Courier');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('payroll.salary-slip', ['slip' => $payload])->render(), 'UTF-8');
        $dompdf->setPaper('a4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }

    private function salarySlipFileName(PayrollSalarySlipSnapshot $snapshot): string
    {
        $period = $snapshot->payload['period'];
        $periodName = $this->monthName((int) $period['month']).' '.$period['year'];

        return $this->safeFileName(sprintf(
            'Salary Slip %s - %s - %s - %s.pdf',
            $snapshot->pt,
            $snapshot->employee_number,
            $snapshot->employee_name,
            $periodName
        ));
    }

    private function zipFileName(AttendancePeriod $period, ?string $pt): string
    {
        return $this->safeFileName(sprintf(
            'Salary Slip - %s - %s %d.zip',
            $this->monthName((int) $period->month),
            $pt ?: 'Semua PT',
            (int) $period->year
        ));
    }

    private function safeFileName(string $fileName): string
    {
        $ascii = Str::ascii($fileName);
        $clean = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/', '-', $ascii);

        return trim(preg_replace('/\s+/', ' ', $clean), ' .');
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
