<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendancePeriodResource;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\ExtraTime;
use App\Models\Overtime;
use App\Models\PayrollCalculation;
use App\Models\PayrollCompletion;
use App\Services\Payroll\PayrollPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year);
        $selectedPeriod = $request->integer('period', now()->month);
        $isAdmin = $request->user()->isAdministrator();
        $cacheKey = "dashboard:v3:{$year}:{$selectedPeriod}:".($isAdmin ? 'admin' : 'staff');

        return response()->json(Cache::remember($cacheKey, now()->addSeconds(45), function () use ($year, $selectedPeriod, $isAdmin) {
            $employeeCounts = Employee::query()
                ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN active IS TRUE THEN 1 ELSE 0 END) AS active')
                ->first();

            $period = app(PayrollPeriodService::class)->attendancePeriod($year, $selectedPeriod);
            $attendance = ['period' => null, 'recorded' => 0, 'expected' => 0, 'progress' => 0];
            $overtime = ['total' => 0, 'updated' => 0, 'progress' => 0];
            $payroll = ['total' => 0, 'completed' => 0, 'pt_total' => count(config('employees.pts')), 'pt_completed' => 0, 'progress' => 0, 'take_home' => 0];
            $recentOvertime = [];
            $periodEmployeeTotal = 0;

            if ($period) {
                $employeeTotal = $period->employeePeriods()->count();
                $periodEmployeeTotal = $employeeTotal;
                $workingDays = $period->dates()->whereRaw('EXTRACT(ISODOW FROM attendance_date) < 6')->count();
                $expected = $employeeTotal * $workingDays;
                $recorded = $period->records()->count();
                $attendance = [
                    'period' => (new AttendancePeriodResource($period))->resolve(),
                    'recorded' => $recorded,
                    'expected' => $expected,
                    'progress' => $expected > 0 ? round($recorded / $expected * 100, 1) : 0,
                ];

                $presentEmployeeIds = AttendanceRecord::query()
                    ->where('attendance_period_id', $period->id)
                    ->whereIn('status', ['M', 'L'])
                    ->distinct()
                    ->pluck('employee_id');
                $updatedEmployeeIds = Overtime::query()->where('payroll_period_id', $period->id)->pluck('employee_id')
                    ->merge(ExtraTime::query()->where('payroll_period_id', $period->id)->pluck('employee_id'))
                    ->unique();
                $overtimeTotal = $presentEmployeeIds->count();
                $updatedTotal = $updatedEmployeeIds->intersect($presentEmployeeIds)->count();
                $overtime = [
                    'total' => $overtimeTotal,
                    'updated' => $updatedTotal,
                    'progress' => $overtimeTotal > 0 ? round($updatedTotal / $overtimeTotal * 100, 1) : 0,
                ];

                $recentOvertime = Overtime::query()->where('payroll_period_id', $period->id)
                    ->with('employee:id,name,branch')
                    ->latest('updated_at')->limit(4)->get()
                    ->map(fn (Overtime $row) => [
                        'employee_id' => $row->employee_id,
                        'employee_name' => $row->employee?->name,
                        'branch' => $row->employee?->branch,
                        'type' => 'Lembur',
                        'updated_at' => $row->updated_at?->toIso8601String(),
                    ])->values()->all();

                $payroll['pt_completed'] = PayrollCompletion::query()
                    ->where('attendance_period_id', $period->id)
                    ->whereIn('pt', config('employees.pts'))
                    ->distinct()
                    ->count('pt');
                $payroll['progress'] = $payroll['pt_total'] > 0
                    ? round($payroll['pt_completed'] / $payroll['pt_total'] * 100, 1)
                    : 0;

                if ($isAdmin) {
                    $payrollRow = PayrollCalculation::query()->where('payroll_period_id', $period->id)
                        ->selectRaw("COUNT(*) AS total, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed, COALESCE(SUM(take_home_pay), 0) AS take_home")
                        ->first();
                    $payrollTotal = (int) $payrollRow->total;
                    $completed = (int) $payrollRow->completed;
                    $payroll = [
                        'total' => $payrollTotal,
                        'completed' => $completed,
                        'pt_total' => $payroll['pt_total'],
                        'pt_completed' => $payroll['pt_completed'],
                        'progress' => $payroll['progress'],
                        'take_home' => (float) $payrollRow->take_home,
                    ];
                }
            }

            return [
                'employees' => [
                    'total' => (int) $employeeCounts->total,
                    'active' => (int) $employeeCounts->active,
                    'in_period' => $periodEmployeeTotal,
                ],
                'attendance' => $attendance,
                'overtime' => $overtime,
                'payroll' => $payroll,
                'recent_overtime' => $recentOvertime,
            ];
        }));
    }
}
