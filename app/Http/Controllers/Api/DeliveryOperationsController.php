<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Operations\DeliveryAssignmentService;
use App\Services\Operations\OperationsDashboardService;
use App\Services\Operations\OrderExecutionService;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryOperationsController extends ApiController
{
    public function __construct(private readonly DeliveryAssignmentService $service, private readonly OperationsDashboardService $dashboardService, private readonly OrderExecutionService $executionService) {}
    public function dashboard(Request $request): JsonResponse
    {
        $data=$request->validate(['branch_id'=>['nullable','integer','exists:branches,id'],'date'=>['nullable','date']]);
        $user=$request->user();
        $branchId=$user?->hasRole('super-admin') ? ($data['branch_id']??null) : $user?->branch_id;
        return $this->success('Operations dashboard fetched', $this->dashboardService->build($branchId ? (int)$branchId : null, $data['date']??today()->toDateString()));
    }
    public function available(Request $request): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['nullable','integer','exists:branches,id'], 'order_id' => ['nullable','integer','exists:orders,id'], 'include_busy' => ['nullable','boolean']]);
        $drivers = $this->service->availableDrivers(isset($data['branch_id']) ? (int) $data['branch_id'] : null, (bool) ($data['include_busy'] ?? false));
        return $this->success('Available delivery drivers fetched', $drivers->map(fn ($driver) => [
            'id'=>$driver->id, 'name'=>$driver->name, 'phone'=>$driver->phone, 'branch_id'=>$driver->branch_id, 'branch'=>$driver->branch,
            'operational_role'=>$driver->operational_role, 'vehicle_type'=>$driver->vehicle_type, 'is_operations_enabled'=>(bool)$driver->is_operations_enabled,
            'calculated_status'=>$driver->calculated_status, 'same_branch'=>$driver->same_branch, 'active_orders_count'=>(int)$driver->active_orders_count,
            'today_delivered_orders_count'=>(int)$driver->today_delivered_orders_count, 'average_delivery_minutes'=>$driver->average_delivery_minutes, 'cash_expected'=>null,
        ])->values());
    }
    public function assign(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['driver_id'=>['required','integer','exists:employees,id'], 'notes'=>['nullable','string','max:1000']]);
        return $this->success('تم تعيين الدليفري وبدء التوصيل', new OrderResource($this->service->assign($order, (int)$data['driver_id'], $data['notes'] ?? null, $request->user())));
    }

    public function assemblers(Request $request): JsonResponse
    {
        $data=$request->validate(['branch_id'=>['nullable','integer','exists:branches,id']]);
        $employees=Employee::query()->with('branch:id,name')->where('operational_role','assembler')->where('is_operations_enabled',true)->where('status','ACTIVE')->when($data['branch_id']??null,fn($q,$id)=>$q->where(fn($b)=>$b->where('branch_id',$id)->orWhereNull('branch_id')))->get(['id','name','phone','branch_id']);
        return $this->success('Active assemblers fetched',$employees);
    }

    public function startAssembly(Request $request, Order $order): JsonResponse
    {
        $data=$request->validate(['assembler_id'=>['required','integer','exists:employees,id'],'notes'=>['nullable','string','max:1000']]);
        return $this->success('تم بدء تجميع الطلب',new OrderResource($this->executionService->startAssembly($order,Employee::findOrFail($data['assembler_id']),$request->user(),$data['notes']??null)));
    }

    public function completeAssembly(Request $request, Order $order): JsonResponse
    {
        $data=$request->validate(['assembler_id'=>['required','integer','exists:employees,id'],'notes'=>['nullable','string','max:1000']]);
        return $this->success('تم إنهاء تجميع الطلب',new OrderResource($this->executionService->completeAssembly($order,Employee::findOrFail($data['assembler_id']),$request->user(),$data['notes']??null)));
    }

    public function events(Order $order): JsonResponse
    {
        return $this->success('Order execution timeline',$order->executionEvents()->with(['employee:id,name','user:id,name'])->get());
    }
}
