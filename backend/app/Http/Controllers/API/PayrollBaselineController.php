<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateEmployeePayrollBaselineRequest;
use App\Http\Requests\UpdatePayrollBaselineRequest;
use App\Http\Resources\EmployeePayrollBaselineResource;
use App\Http\Resources\PayrollBaselineResource;
use App\Models\Employee;
use App\Services\Payroll\PayrollBaselineService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayrollBaselineController extends Controller
{
    public function __construct(private readonly PayrollBaselineService $baselines) {}

    public function employees(Request $request): AnonymousResourceCollection
    {
        return EmployeePayrollBaselineResource::collection($this->baselines->employees((string) $request->query('search', ''), (string) $request->query('pt', '')));
    }

    public function updateEmployee(UpdateEmployeePayrollBaselineRequest $request, Employee $employee): EmployeePayrollBaselineResource
    {
        $baseline = $this->baselines->updateEmployee($employee, $request->validated());
        $employee->setAttribute('baseline_pt', $baseline->pt);
        $employee->setRelation('payrollBaseline', $baseline);

        return new EmployeePayrollBaselineResource($employee);
    }

    public function configuration(): PayrollBaselineResource
    {
        return new PayrollBaselineResource($this->baselines->configuration());
    }

    public function updateConfiguration(UpdatePayrollBaselineRequest $request): PayrollBaselineResource
    {
        return new PayrollBaselineResource($this->baselines->updateConfiguration($request->validated()));
    }
}
