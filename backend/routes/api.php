<?php

use App\Http\Controllers\API\AttendanceController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\EmployeeController;
use App\Http\Controllers\API\OvertimeController;
use App\Http\Controllers\API\PayrollBaselineController;
use App\Http\Controllers\API\PayrollCalculationController;
use App\Http\Controllers\API\PayrollCompletionController;
use App\Http\Controllers\API\PayrollManualAdjustmentController;
use App\Http\Controllers\API\PayrollRecapController;
use App\Http\Controllers\API\PayrollSalarySlipController;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('dashboard', DashboardController::class);
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('employees/divisions', [EmployeeController::class, 'divisions']);
    Route::get('employees/{employee}/loan-history', [EmployeeController::class, 'loanHistory'])->middleware('admin');
    Route::post('employees/{employee}/loan/clear', [EmployeeController::class, 'clearLoan'])->middleware('admin');
    Route::apiResource('employees', EmployeeController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::get('attendance', [AttendanceController::class, 'index']);
    Route::post('attendance/import', [AttendanceController::class, 'import']);
    Route::post('attendance/import-preview', [AttendanceController::class, 'preview']);
    Route::get('attendance/export', [AttendanceController::class, 'export']);
    Route::patch('attendance/cell', [AttendanceController::class, 'cell']);
    Route::patch('attendance/overtime', [AttendanceController::class, 'overtime']);
    Route::post('attendance/lock', [AttendanceController::class, 'lock']);
    Route::get('overtime', [OvertimeController::class, 'index']);
    Route::post('overtime/import', [OvertimeController::class, 'import']);
    Route::get('overtime/{attendancePeriod}/employees/{employee}', [OvertimeController::class, 'employeeDetails']);
    Route::put('overtime/{attendancePeriod}/employees/{employee}', [OvertimeController::class, 'saveEmployeeDetails']);
    Route::get('overtime/{attendancePeriod}/employees/{employee}/extra-time', [OvertimeController::class, 'extraTimeDetails']);
    Route::put('overtime/{attendancePeriod}/employees/{employee}/extra-time', [OvertimeController::class, 'saveExtraTimeDetails']);
    Route::get('overtime/{attendancePeriod}', [OvertimeController::class, 'show']);
    Route::post('overtime', [OvertimeController::class, 'store']);
    Route::put('overtime/{overtime}', [OvertimeController::class, 'update']);
    Route::delete('overtime/{overtime}', [OvertimeController::class, 'destroy']);
    Route::middleware('admin')->group(function () {
        Route::get('payroll-baselines/employees', [PayrollBaselineController::class, 'employees']);
        Route::put('payroll-baselines/employees/{employee}', [PayrollBaselineController::class, 'updateEmployee']);
        Route::get('payroll-baselines/configuration', [PayrollBaselineController::class, 'configuration']);
        Route::put('payroll-baselines/configuration', [PayrollBaselineController::class, 'updateConfiguration']);
        Route::post('payroll-calculations/calculate', [PayrollCalculationController::class, 'calculate']);
        Route::get('payroll-calculations/{attendancePeriod}', [PayrollCalculationController::class, 'index']);
        Route::get('payroll-calculations/{attendancePeriod}/pph21-data/download', [PayrollCalculationController::class, 'downloadPph21Data']);
        Route::get('payroll-salary-slips/{attendancePeriod}', [PayrollSalarySlipController::class, 'download']);
        Route::get('payroll-recaps/{attendancePeriod}/summary', [PayrollRecapController::class, 'summary']);
        Route::get('payroll-recaps/{attendancePeriod}', [PayrollRecapController::class, 'download']);
        Route::post('payroll-calculations/{payrollCalculation}/manual-adjustments', [PayrollManualAdjustmentController::class, 'store']);
        Route::put('payroll-calculations/{payrollCalculation}/manual-adjustments/{payrollManualAdjustment}', [PayrollManualAdjustmentController::class, 'update']);
        Route::delete('payroll-calculations/{payrollCalculation}/manual-adjustments/{payrollManualAdjustment}', [PayrollManualAdjustmentController::class, 'destroy']);
        Route::get('payroll-completions/{attendancePeriod}', [PayrollCompletionController::class, 'status']);
        Route::post('payroll-completions/complete', [PayrollCompletionController::class, 'complete']);
        Route::post('payroll-completions/undo', [PayrollCompletionController::class, 'undo']);
    });
});
