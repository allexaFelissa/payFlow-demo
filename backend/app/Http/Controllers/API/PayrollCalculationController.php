<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\CalculatePayrollRequest;
use App\Http\Requests\DownloadPph21DataRequest;
use App\Http\Resources\PayrollCalculationResource;
use App\Models\AttendancePeriod;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\Pph21DataExportService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PayrollCalculationController extends Controller
{
    public function __construct(private readonly PayrollCalculationService $calculations, private readonly Pph21DataExportService $pph21Data) {}

    public function calculate(CalculatePayrollRequest $request): AnonymousResourceCollection
    {
        $data = $request->validated();

        return PayrollCalculationResource::collection($this->calculations->calculate(AttendancePeriod::findOrFail($data['payroll_period_id']), $data));
    }

    public function index(AttendancePeriod $attendancePeriod): AnonymousResourceCollection
    {
        return PayrollCalculationResource::collection($this->calculations->drafts($attendancePeriod));
    }

    public function downloadPph21Data(AttendancePeriod $attendancePeriod, DownloadPph21DataRequest $request): BinaryFileResponse
    {
        $file = $this->pph21Data->create($attendancePeriod, $request->validated('pt'));
        return response()->download($file['path'], $file['name'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
