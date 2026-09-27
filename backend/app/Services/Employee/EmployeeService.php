<?php

namespace App\Services\Employee;

use App\Models\Employee;
use App\Models\PayrollCompletionLoan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeService
{
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = Employee::query()->orderBy('name');
        if ($filters['search'] ?? null) {
            $search = $filters['search'];
            $query->where(fn ($q) => $q->whereLike('name', '%'.$search.'%')
                ->orWhereLike('employee_number', '%'.$search.'%')
                ->orWhereLike('email', '%'.$search.'%')
                ->orWhereLike('position', '%'.$search.'%'));
        }
        if ($filters['branch'] ?? $filters['department'] ?? null) {
            $query->assignedToPt($filters['branch'] ?? $filters['department']);
        }
        if (($filters['active'] ?? '') !== '') {
            $query->where('active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->paginate($perPage);
    }

    public function divisions(): array
    {
        return array_map(fn ($division) => [
            'name' => $division,
            'employee_count' => Employee::query()->assignedToPt($division)->count(),
        ], config('employees.divisions'));
    }

    public function create(array $attributes): Employee
    {
        $attributes = $this->normalizePts($attributes);
        if ((float) ($attributes['loan_amount'] ?? 0) > 0) {
            $attributes['loan_balance'] = $attributes['loan_amount'];
        }

        return Employee::create($attributes);
    }

    public function update(Employee $employee, array $attributes): Employee
    {
        return DB::transaction(function () use ($employee, $attributes) {
            $attributes = $this->normalizePts($attributes);
            if ((float) $employee->loan_balance > 0
                && array_key_exists('loan_amount', $attributes)
                && round((float) $attributes['loan_amount'], 2) !== round((float) $employee->loan_amount, 2)) {
                throw ValidationException::withMessages([
                    'loan_amount' => 'Jumlah Pinjaman Awal tidak dapat diubah setelah pinjaman dibuat.',
                ]);
            }
            if ((float) $employee->loan_balance <= 0 && (float) ($attributes['loan_amount'] ?? 0) > 0) {
                $attributes['loan_balance'] = $attributes['loan_amount'];
            }
            $employee->update($attributes);
            $this->removeBaselinesOutsideEmployeePts($employee);

            return $employee->refresh();
        });
    }

    public function loanHistory(Employee $employee): Collection
    {
        return PayrollCompletionLoan::query()
            ->where('employee_id', $employee->id)
            ->with('completion.period')
            ->get()
            ->sortByDesc(function (PayrollCompletionLoan $finalization) {
                $period = $finalization->completion?->period;

                return sprintf(
                    '%04d-%02d-%s',
                    (int) ($period?->year ?? 0),
                    (int) ($period?->month ?? 0),
                    $finalization->completion?->created_at?->format('Y-m-d H:i:s.u') ?? ''
                );
            })
            ->values();
    }

    public function clearLoan(Employee $employee): Employee
    {
        return DB::transaction(function () use ($employee) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);

            $employee->payrollCalculations()
                ->where('status', 'draft')
                ->with('loanDetail')
                ->lockForUpdate()
                ->get()
                ->each(function ($calculation) {
                    $loanDeduction = (float) $calculation->potongan_pinjaman;

                    $calculation->update([
                        'potongan_pinjaman' => 0,
                        'total_deduction' => round(max(0, (float) $calculation->total_deduction - $loanDeduction), 2),
                        'take_home_pay' => round((float) $calculation->take_home_pay + $loanDeduction, 2),
                    ]);
                    $calculation->loanDetail?->update([
                        'loan_amount' => 0,
                        'installment_amount' => 0,
                        'deducted_amount' => 0,
                        'remaining_before' => 0,
                        'remaining_after' => 0,
                        'loan_start_date' => null,
                        'loan_notes' => null,
                    ]);
                });

            $employee->update([
                'loan_amount' => 0,
                'loan_balance' => 0,
                'loan_installment' => 0,
                'loan_start_date' => null,
                'loan_notes' => null,
            ]);

            return $employee->refresh();
        });
    }

    public function delete(Employee $employee): void
    {
        $employee->delete();
    }

    private function normalizePts(array $attributes): array
    {
        $pts = array_values(array_unique($attributes['pts'] ?? array_filter([
            $attributes['branch'] ?? $attributes['department'] ?? null,
        ])));

        if ($pts) {
            $attributes['pts'] = $pts;
            // branch remains the primary payroll PT for compatibility with payroll records.
            $attributes['branch'] = in_array($attributes['branch'] ?? null, $pts, true)
                ? $attributes['branch']
                : $pts[0];
            $attributes['department'] = $attributes['branch'];
        }

        return $attributes;
    }

    private function removeBaselinesOutsideEmployeePts(Employee $employee): void
    {
        $pts = $employee->assignedPts();
        $query = $employee->payrollBaselines();

        $pts === []
            ? $query->delete()
            : $query->whereNotIn('pt', $pts)->delete();
    }
}
