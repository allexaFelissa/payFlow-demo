<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavePayrollManualAdjustmentRequest;
use App\Http\Resources\PayrollCalculationResource;
use App\Models\PayrollCalculation;
use App\Models\PayrollManualAdjustment;
use App\Services\Payroll\PayrollManualAdjustmentService;

class PayrollManualAdjustmentController extends Controller
{
    public function __construct(private readonly PayrollManualAdjustmentService $adjustments) {}

    public function store(SavePayrollManualAdjustmentRequest $request, PayrollCalculation $payrollCalculation): PayrollCalculationResource
    {
        return new PayrollCalculationResource($this->adjustments->create($payrollCalculation, $request->validated()));
    }

    public function update(SavePayrollManualAdjustmentRequest $request, PayrollCalculation $payrollCalculation, PayrollManualAdjustment $payrollManualAdjustment): PayrollCalculationResource
    {
        return new PayrollCalculationResource($this->adjustments->update($payrollCalculation, $payrollManualAdjustment, $request->validated()));
    }

    public function destroy(PayrollCalculation $payrollCalculation, PayrollManualAdjustment $payrollManualAdjustment): PayrollCalculationResource
    {
        return new PayrollCalculationResource($this->adjustments->delete($payrollCalculation, $payrollManualAdjustment));
    }
}
