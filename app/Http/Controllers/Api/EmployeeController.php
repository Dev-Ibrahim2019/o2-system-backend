<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\V1\EmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Services\Accounting\SubledgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends ApiController
{
    public function __construct(private readonly SubledgerService $subledgerService) {}

    public function index(Request $request): JsonResponse
    {
        $employees = Employee::query()
            ->with([
                'branch:id,name',
                'department:id,name',
                'jobTitle:id,name,description,is_active',
                'manager:id,name',
            ])
            ->when($request->branch_id, fn ($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->status, fn ($q) => $q->where('status', strtoupper((string) $request->status)))
            ->when($request->salary_type, fn ($q) => $q->where('salary_type', $request->salary_type))
            ->when($request->search, fn ($q) => $q->where(fn ($qb) =>
                $qb->where('name', 'like', "%{$request->search}%")
                    ->orWhere('phone', 'like', "%{$request->search}%")
                    ->orWhere('employeeId', 'like', "%{$request->search}%")
                    ->orWhere('nationalId', 'like', "%{$request->search}%")
            ))
            ->orderBy('name')
            ->paginate(min((int) ($request->per_page ?? 100), 200));

        return $this->success('Employees fetched', [
            'data' => EmployeeResource::collection($employees->items()),
            'pagination' => [
                'current_page' => $employees->currentPage(),
                'last_page' => $employees->lastPage(),
                'total' => $employees->total(),
                'per_page' => $employees->perPage(),
            ],
        ]);
    }

    public function store(EmployeeRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (!empty($data['password'])) $data['password'] = bcrypt($data['password']);
        if (empty($data['managerId'])) $data['managerId'] = null;
        if (empty($data['jobTitleId'])) $data['jobTitleId'] = null;
        $data['salary_type'] ??= 'monthly';
        $data['standard_daily_hours'] ??= 8;

        $employee = Employee::create($data);

        return $this->success('Employee created', new EmployeeResource($this->loadRelations($employee)), 201);
    }

    public function show(Employee $employee): JsonResponse
    {
        return $this->success('Employee fetched', new EmployeeResource($this->loadRelations($employee)));
    }

    public function update(EmployeeRequest $request, Employee $employee): JsonResponse
    {
        $data = $request->validated();
        if (!empty($data['password'])) $data['password'] = bcrypt($data['password']);
        else unset($data['password']);
        if (array_key_exists('managerId', $data) && empty($data['managerId'])) $data['managerId'] = null;
        if (array_key_exists('jobTitleId', $data) && empty($data['jobTitleId'])) $data['jobTitleId'] = null;

        $employee->update($data);
        return $this->success('Employee updated', new EmployeeResource($this->loadRelations($employee->fresh())));
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $balances = $this->subledgerService->getEmployeeBalances($employee->id);
        $hasFinancialBalance = collect([
            $balances['outstanding_advance'] ?? 0,
            $balances['outstanding_loan'] ?? 0,
            $balances['accrued_salary'] ?? 0,
        ])->contains(fn ($value) => abs((float) $value) > 0.001);

        if ($hasFinancialBalance) {
            return $this->error(
                'لا يمكن حذف موظف لديه أرصدة مالية قائمة. صفِّ السلف/القروض/الرواتب أولاً أو غيّر حالته إلى منتهي/مستقيل.',
                422
            );
        }

        $employee->delete();
        return $this->success('Employee deleted', []);
    }

    private function loadRelations(Employee $employee): Employee
    {
        return $employee->load([
            'branch:id,name',
            'department:id,name',
            'jobTitle:id,name,description,is_active',
            'manager:id,name',
        ]);
    }
}
