<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\DownloadPayrollSalarySlipsRequest;
use App\Models\AttendancePeriod;
use App\Services\Payroll\PayrollSalarySlipService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PayrollSalarySlipController extends Controller
{
    public function __construct(private readonly PayrollSalarySlipService $salarySlips) {}

    public function download(
        AttendancePeriod $attendancePeriod,
        DownloadPayrollSalarySlipsRequest $request
    ): BinaryFileResponse {
        $archive = $this->salarySlips->createZip($attendancePeriod, $request->validated());

        return response()
            ->download($archive['path'], $archive['name'], ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }
}
