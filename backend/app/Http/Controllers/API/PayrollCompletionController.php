<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\PayrollCompletionRequest;
use App\Models\AttendancePeriod;
use App\Services\Payroll\PayrollCompletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollCompletionController extends Controller
{
    public function __construct(private readonly PayrollCompletionService $completions) {}

    public function status(AttendancePeriod $attendancePeriod, Request $request): JsonResponse
    {
        $data = $request->validate(['pt' => ['required', 'string']]);

        return response()->json(['completed' => $this->completions->status($attendancePeriod, $data['pt'])]);
    }

    public function complete(PayrollCompletionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->completions->complete(AttendancePeriod::findOrFail($data['payroll_period_id']), $data['pt'], $request->user()?->id);

        return response()->json(['completed' => true]);
    }

    public function undo(PayrollCompletionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->completions->undo(AttendancePeriod::findOrFail($data['payroll_period_id']), $data['pt']);

        return response()->json(['completed' => false]);
    }
}
