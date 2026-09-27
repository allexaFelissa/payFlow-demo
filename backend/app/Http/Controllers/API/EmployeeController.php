<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeLoanHistoryResource;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Services\Employee\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Employee::class);

        return EmployeeResource::collection($this->employees->paginate(15, $request->only('search', 'active', 'branch', 'department')));
    }

    public function divisions(): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        return response()->json(['data' => $this->employees->divisions()]);
    }

    public function store(StoreEmployeeRequest $request): EmployeeResource
    {
        return new EmployeeResource($this->employees->create($request->validated()));
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        return new EmployeeResource($this->employees->update($employee, $request->validated()));
    }

    public function show(Employee $employee): EmployeeResource
    {
        $this->authorize('view', $employee);

        return new EmployeeResource($employee);
    }

    public function loanHistory(Employee $employee): AnonymousResourceCollection
    {
        $this->authorize('manage-loans');

        return EmployeeLoanHistoryResource::collection($this->employees->loanHistory($employee));
    }

    public function clearLoan(Employee $employee): EmployeeResource
    {
        $this->authorize('manage-loans');

        return new EmployeeResource($this->employees->clearLoan($employee));
    }

    public function destroy(Employee $employee): Response
    {
        $this->authorize('delete', $employee);
        $this->employees->delete($employee);

        return response()->noContent();
    }
}
