<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeePayrollBaseline;
use App\Models\PayrollBaseline;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollBaselineService
{
    public function employees(string $search = '', string $pt = '', int $perPage = 15): LengthAwarePaginator
    {
        $employees = Employee::query()
            ->with('payrollBaselines')
            ->when($search, fn ($query) => $query->where(fn ($nested) => $nested
                ->whereLike('name', '%'.$search.'%')
                ->orWhereLike('employee_number', '%'.$search.'%')
                ->orWhereLike('tax_number', '%'.$search.'%')))
            ->when($pt, fn ($query) => $query->assignedToPt($pt))
            ->orderBy('name')
            ->get();

        $rows = $employees->flatMap(function (Employee $employee) use ($pt) {
            $pts = collect($employee->assignedPts())
                ->when($pt, fn ($assigned) => $assigned->filter(fn ($value) => $value === $pt));

            return $pts->map(function (string $assignedPt) use ($employee) {
                $row = clone $employee;
                $row->setAttribute('baseline_pt', $assignedPt);
                $row->setRelation(
                    'payrollBaseline',
                    $employee->payrollBaselines->firstWhere('pt', $assignedPt)
                );

                return $row;
            });
        })->values();

        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    public function updateEmployee(Employee $employee, array $data): EmployeePayrollBaseline
    {
        $pt = $data['pt'];
        $hasTitle = array_key_exists('title', $data);
        $hasFamilyStatus = array_key_exists('family_status', $data);
        $title = $data['title'] ?? null;
        $familyStatus = $data['family_status'] ?? null;
        unset($data['pt'], $data['title'], $data['family_status']);

        if (! in_array($pt, $employee->assignedPts(), true)) {
            throw ValidationException::withMessages([
                'pt' => 'PT baseline harus sesuai dengan PT yang terdaftar pada Data Karyawan.',
            ]);
        }

        $this->rejectContributionTotalsUsedAsBpjsBases($data);

        return DB::transaction(function () use ($employee, $data, $pt, $title, $familyStatus, $hasTitle, $hasFamilyStatus) {
            $employeeData = [];
            if ($hasTitle) {
                $employeeData['position'] = $title;
            }
            if ($hasFamilyStatus) {
                $employeeData['family_status'] = $familyStatus;
            }
            if ($employeeData !== []) {
                $employee->update($employeeData);
            }

            return EmployeePayrollBaseline::updateOrCreate(
                ['employee_id' => $employee->id, 'pt' => $pt],
                $data
            );
        });
    }

    private function rejectContributionTotalsUsedAsBpjsBases(array $data): void
    {
        $configuration = $this->configuration();
        $tkRate = (float) $configuration->jht_pens + (float) $configuration->jkk_jkm + (float) $configuration->potgn_tk;
        $healthRate = (float) $configuration->bpjs_p + (float) $configuration->potgn_kes;
        $storedTk = (float) ($data['dasar_perhitungan_bpjs_tk'] ?? 0);
        $storedHealth = (float) ($data['dasar_perhitungan_bpjs_kes'] ?? 0);

        if ($tkRate <= 0 || $healthRate <= 0 || $storedTk <= 0 || $storedHealth <= 0) {
            return;
        }

        $derivedTkBase = $storedTk / ($tkRate / 100);
        $derivedHealthBase = $storedHealth / ($healthRate / 100);
        if (abs($derivedTkBase - $derivedHealthBase) <= max(1, $derivedHealthBase * .001)) {
            throw ValidationException::withMessages([
                'dasar_perhitungan_bpjs_tk' => 'Masukkan dasar upah BPJS, bukan nominal iuran BPJS TK.',
                'dasar_perhitungan_bpjs_kes' => 'Masukkan dasar upah BPJS, bukan nominal iuran BPJS Kesehatan.',
            ]);
        }
    }

    public function configuration(): PayrollBaseline
    {
        return PayrollBaseline::active()->latest('id')->first() ?? PayrollBaseline::create($this->defaults());
    }

    public function updateConfiguration(array $data): PayrollBaseline
    {
        return DB::transaction(function () use ($data) {
            $derivedBpjs = round($data['jht_pens'] + $data['jkk_jkm'] + $data['bpjs_p'], 2);
            $baseline = PayrollBaseline::active()->latest('id')->first()
                ?? PayrollBaseline::query()->latest('id')->first()
                ?? PayrollBaseline::create($this->defaults());
            PayrollBaseline::query()->whereKeyNot($baseline->id)->update(['is_active' => false]);
            $baseline->update([...$data, 'tunj_bpjs' => $derivedBpjs, 'tunj_prshan' => $derivedBpjs, 'is_active' => true]);

            return $baseline->refresh();
        });
    }

    private function defaults(): array
    {
        return ['jht_pens' => 5.70, 'jkk_jkm' => 0.54, 'bpjs_p' => 4.00, 'tunj_bpjs' => 10.24, 'potgn_tk' => 3.00, 'tunj_prshan' => 10.24, 'potgn_kes' => 1.00, 'lembur_multiplier' => 1.50, 'lembur_hari_kerja_rate' => 10000, 'lembur_hari_libur_multiplier' => 2.00, 'insentif_default' => 10.00, 'is_active' => true];
    }
}
