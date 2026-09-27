<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Overtime\ImportOvertimeRequest;
use App\Http\Requests\Overtime\SaveExtraTimeDetailsRequest;
use App\Http\Requests\Overtime\SaveOvertimeDetailsRequest;
use App\Http\Requests\Overtime\StoreOvertimeRequest;
use App\Http\Requests\Overtime\UpdateOvertimeRequest;
use App\Http\Resources\AttendancePeriodResource;
use App\Http\Resources\OvertimeResource;
use App\Models\AttendancePeriod;
use App\Models\Employee;
use App\Models\Overtime;
use App\Services\Overtime\ExtraTimeService;
use App\Services\Overtime\OvertimeImportService;
use App\Services\Overtime\OvertimeService;
use App\Services\Payroll\PayrollPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class OvertimeController extends Controller
{
    public function __construct(
        private readonly OvertimeService $overtime,
        private readonly ExtraTimeService $extraTime,
        private readonly OvertimeImportService $imports,
    ) {}

    public function import(ImportOvertimeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->imports->import($request->file('file'), $data['period'], $data['year']);

        return response()->json([
            'period' => new AttendancePeriodResource($result['period']),
            'imported_employees' => $result['imported'],
            'skipped_employees' => $result['skipped'],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['nullable', 'integer', 'between:1,12', 'required_without:month'], 'month' => ['nullable', 'integer', 'between:1,12', 'required_without:period'], 'year' => ['required', 'integer', 'digits:4'], 'branch' => ['nullable', 'string', 'max:100'], 'search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $selectedPeriod = $data['period'] ?? $data['month'];
        $period = app(PayrollPeriodService::class)->attendancePeriod($data['year'], $selectedPeriod);
        if (! $period) {
            return response()->json(['period' => null, 'data' => []]);
        }

        return $this->response($period, $data);
    }

    public function show(AttendancePeriod $attendancePeriod, Request $request): JsonResponse
    {
        return $this->response($attendancePeriod, $request->validate(['branch' => ['nullable', 'string', 'max:100'], 'search' => ['nullable', 'string', 'max:100']]));
    }

    public function employeeDetails(AttendancePeriod $attendancePeriod, Employee $employee): JsonResponse
    {
        return response()->json(['employee' => ['id' => $employee->id, 'employee_number' => $employee->employee_number, 'name' => $employee->name, 'position' => $employee->position, 'branch' => $employee->branch, 'overtime_eligible' => ! $this->extraTime->eligible($employee), 'extra_time_eligible' => $this->extraTime->eligible($employee)], 'rates' => $this->overtime->calculationRates($employee), 'data' => $this->overtime->details($attendancePeriod, $employee)]);
    }

    public function saveEmployeeDetails(SaveOvertimeDetailsRequest $request, AttendancePeriod $attendancePeriod, Employee $employee): JsonResponse
    {
        return (new OvertimeResource($this->overtime->saveDetails($attendancePeriod, $employee, $request->validated('rows'))))->response()->setStatusCode(200);
    }

    public function extraTimeDetails(AttendancePeriod $attendancePeriod, Employee $employee): JsonResponse
    {
        return response()->json($this->extraTime->details($attendancePeriod, $employee));
    }

    public function saveExtraTimeDetails(SaveExtraTimeDetailsRequest $request, AttendancePeriod $attendancePeriod, Employee $employee): JsonResponse
    {
        return response()->json($this->extraTime->save($attendancePeriod, $employee, $request->validated('rows')));
    }

    public function store(StoreOvertimeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $period = AttendancePeriod::findOrFail($data['payroll_period_id']);

        return $this->response($period, [], $this->overtime->save($period, $data['rows']));
    }

    public function update(UpdateOvertimeRequest $request, Overtime $overtime): OvertimeResource
    {
        return new OvertimeResource($this->overtime->update($overtime, $request->validated()));
    }

    public function destroy(Overtime $overtime): JsonResponse
    {
        $this->overtime->delete($overtime);

        return response()->noContent();
    }

    private function response(AttendancePeriod $period, array $filters = [], $rows = null): JsonResponse
    {
        $result = $rows ?? $this->overtime->rows($period, $filters);
        $pagination = $result instanceof LengthAwarePaginator ? [
            'current_page' => $result->currentPage(),
            'last_page' => $result->lastPage(),
            'per_page' => $result->perPage(),
            'total' => $result->total(),
        ] : null;

        return response()->json([
            'period' => new AttendancePeriodResource($period),
            'branches' => $this->overtime->branches($period),
            'data' => OvertimeResource::collection($result instanceof LengthAwarePaginator ? $result->items() : $result),
            'meta' => $pagination,
        ]);
    }
}
