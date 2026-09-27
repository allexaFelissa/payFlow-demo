<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\GenerateAttendanceRequest;
use App\Http\Requests\Attendance\ImportAttendanceRequest;
use App\Http\Requests\Attendance\LockAttendanceRequest;
use App\Http\Requests\Attendance\PreviewAttendanceImportRequest;
use App\Http\Requests\Attendance\UpdateAttendanceCellRequest;
use App\Http\Requests\Attendance\UpdateOvertimeRequest;
use App\Http\Resources\AttendancePeriodResource;
use App\Http\Resources\AttendanceResource;
use App\Models\User;
use App\Services\Attendance\AttendanceExportService;
use App\Services\Attendance\AttendanceImportService;
use App\Services\Attendance\AttendanceMatrixService;
use App\Services\Payroll\PayrollPeriodService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceMatrixService $matrix, private readonly AttendanceImportService $imports, private readonly AttendanceExportService $exports) {}

    public function index(Request $request): JsonResponse
    {
        $period = app(PayrollPeriodService::class)->attendancePeriod($request->integer('year', now()->year), $request->integer('period', now()->month));

        if (! $period) {
            return response()->json(['period' => null, 'data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]]);
        }

        $employees = $this->matrix->matrix($period, $request->only(['search', 'department', 'branch', 'active']));
        $employees->load([
            'attendanceRecords' => fn ($query) => $query->where('attendance_period_id', $period->id),
            'attendancePeriodEmployee' => fn ($query) => $query->where('attendance_period_id', $period->id),
        ]);

        return response()->json([
            'period' => new AttendancePeriodResource($period),
            'dates' => $period->dates()->orderBy('attendance_date')->pluck('attendance_date')->map(fn ($date) => Carbon::parse($date)->toDateString())->values(),
            'data' => AttendanceResource::collection($employees->items()),
            'meta' => ['current_page' => $employees->currentPage(), 'last_page' => $employees->lastPage(), 'total' => $employees->total()],
        ]);
    }

    public function generate(GenerateAttendanceRequest $request): AttendancePeriodResource
    {
        $data = $request->validated();

        return new AttendancePeriodResource($this->matrix->generate($data['month'], $data['year'], $this->userId($request)));
    }

    public function import(ImportAttendanceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->imports->import($request->file('file'), $data['period'], $data['year'], $this->importUserId($request));

        return response()->json([
            'data' => new AttendancePeriodResource($result['period']),
            'skipped_employees' => $result['skipped'],
            'skipped_dates' => $result['skipped_dates'],
        ]);
    }

    public function preview(PreviewAttendanceImportRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->imports->preview($request->file('file'))]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $year = $request->integer('year', now()->year);
        $month = $request->integer('period', now()->month);
        $period = app(PayrollPeriodService::class)->attendancePeriod($year, $month);
        abort_unless($period, 404);
        $path = $this->exports->export($period);

        return response()->download($path, sprintf('attendance-%04d-%02d.xlsx', $year, $month))->deleteFileAfterSend(true);
    }

    public function cell(UpdateAttendanceCellRequest $request): JsonResponse
    {
        $record = $this->matrix->updateCell($request->validated());

        return response()->json(['record' => $record ? ['id' => $record->id, 'status' => $record->status->value, 'remarks' => $record->remarks] : null]);
    }

    public function overtime(UpdateOvertimeRequest $request): JsonResponse
    {
        $row = $this->matrix->updateOvertime($request->validated());

        return response()->json(['overtime_hours' => (float) $row->monthly_overtime_hours]);
    }

    public function lock(LockAttendanceRequest $request): AttendancePeriodResource
    {
        return new AttendancePeriodResource($this->matrix->lock($request->integer('attendance_period_id')));
    }

    private function periodFromRequest(Request $request): array
    {
        $dates = app(PayrollPeriodService::class)->dates($request->integer('year', now()->year), $request->integer('period', now()->month));

        return [$dates['end']->year, $dates['end']->month];
    }

    private function importUserId(Request $request): int
    {
        return $request->user()?->id ?? User::query()->value('id') ?? throw new \RuntimeException('Create an application user before importing attendance.');
    }

    private function userId(Request $request): int
    {
        return $request->user()?->id ?? User::query()->value('id') ?? throw new \RuntimeException('Create an application user before managing attendance.');
    }
}
