<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\DownloadPayrollRecapRequest;
use App\Models\AttendancePeriod;
use App\Services\Payroll\PayrollRecapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PayrollRecapController extends Controller
{
    public function __construct(private readonly PayrollRecapService $recaps) {}

    public function download(
        AttendancePeriod $attendancePeriod,
        DownloadPayrollRecapRequest $request
    ): BinaryFileResponse {
        $file = $this->recaps->create($attendancePeriod, $request->validated());

        return response()
            ->download(
                $file['path'],
                $file['name'],
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
            )
            ->deleteFileAfterSend(true);
    }

    public function summary(AttendancePeriod $attendancePeriod, Request $request): JsonResponse
    {
        $filters = $request->validate([
            'pt' => ['required', 'string', Rule::in(config('employees.pts'))],
            'search' => ['nullable', 'string', 'max:150'],
        ]);

        return response()->json(['data' => $this->recaps->summary($attendancePeriod, $filters)]);
    }
}
