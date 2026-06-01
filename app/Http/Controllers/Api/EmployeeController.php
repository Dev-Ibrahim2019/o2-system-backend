<?php
// app/Http/Controllers/Api/EmployeeController.php
// ✅ إصلاح الأداء:
// 1. إضافة pagination بدل جلب كل الموظفين دفعة واحدة
// 2. select الحقول الضرورية فقط بدل SELECT *
// 3. eager loading صحيح بدون N+1

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\V1\EmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\Request;
use App\Models\Account;
use Illuminate\Support\Facades\DB;

class EmployeeController extends ApiController
{
    public function index(Request $request)
    {
        $employees = Employee::with([
            'branch:id,name',       // ✅ select الحقول الضرورية فقط
            'department:id,name',
        ])
            ->select([                   // ✅ بدل SELECT * نختار ما نحتاجه
                'id',
                'name',
                'phone',
                'email',
                'image',
                'branch_id',
                'department_id',
                'jobTitleId',
                'role',
                'status',
                'hireDate',
                'salary',
                'employeeId',
                'username',
                'rating',
                'permissions',
                'notes',
            ])
            ->when($request->branch_id,     fn($q) => $q->where('branch_id',     $request->branch_id))
            ->when($request->department_id, fn($q) => $q->where('department_id', $request->department_id))
            ->when($request->status,        fn($q) => $q->where('status',        $request->status))
            ->when($request->search,        fn($q) => $q->where(
                fn($qb) =>
                $qb->where('name',  'like', "%{$request->search}%")
                    ->orWhere('phone', 'like', "%{$request->search}%")
                    ->orWhere('employeeId', 'like', "%{$request->search}%")
            ))
            // ✅ pagination: 50 موظف بالصفحة بدل جلب الكل
            ->paginate($request->per_page ?? 50);

        return $this->success('Employees fetched', [
            'data'       => EmployeeResource::collection($employees->items()),
            'pagination' => [
                'current_page' => $employees->currentPage(),
                'last_page'    => $employees->lastPage(),
                'total'        => $employees->total(),
                'per_page'     => $employees->perPage(),
            ],
        ]);
    }



    public function store(EmployeeRequest $request)
    {
        $data = $request->validated();

        if (!empty($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        }

        DB::beginTransaction();
        try {
            // إنشاء الحساب المحاسبي تلقائياً
            $account = Account::create([
                'name'          => $data['name'] . ' - حساب موظف',
                'code'          => $this->generateEmployeeAccountCode(),
                'type'          => 'asset',
                'normal_balance' => 'debit',
                'parent_id'     => $this->getEmployeesParentAccountId(),
                'allow_posting' => true,
                'is_active'     => true,
                'is_system'     => false,
                'level'         => 3,
                'notes'         => 'حساب تلقائي للموظف: ' . $data['name'],
            ]);

            $data['account_id'] = $account->id;
            $employee = Employee::create($data);

            DB::commit();
            return $this->success(
                'Employee created',
                new EmployeeResource($employee->load(['branch:id,name', 'department:id,name'])),
                201
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('فشل إنشاء الموظف: ' . $e->getMessage(), 500);
        }
    }

    private function generateEmployeeAccountCode(): string
    {
        // افتراض أن حسابات الموظفين تبدأ بـ 1400
        $prefix = '14';
        $last = Account::where('code', 'like', $prefix . '%')
            ->orderByDesc('code')->value('code');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    private function getEmployeesParentAccountId(): ?int
    {
        // أنشئ هذا الحساب يدوياً في قاعدة البيانات أو عبر seeder
        return Account::where('code', '14')->value('id');
    }

    public function show(Employee $employee)
    {
        return $this->success(
            'Employee fetched',
            new EmployeeResource($employee->load(['branch:id,name', 'department:id,name']))
        );
    }

    public function update(EmployeeRequest $request, Employee $employee)
    {
        $data = $request->validated();

        if (!empty($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        } else {
            unset($data['password']);
        }

        $employee->update($data);

        return $this->success(
            'Employee updated',
            new EmployeeResource($employee->load(['branch:id,name', 'department:id,name']))
        );
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();
        return $this->success('Employee deleted', []);
    }
}
