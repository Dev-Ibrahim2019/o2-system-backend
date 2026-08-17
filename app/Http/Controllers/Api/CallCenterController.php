<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Customer;
use App\Models\CustomerComplaint;
use App\Services\CallCenter\CallCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CallCenterController extends ApiController
{
    public function __construct(
        private readonly CallCenterService $callCenterService,
    ) {}

    /**
     * POST /api/call-center/customers
     */
    public function storeCustomer(Request $request): JsonResponse
    {
        $normalize = static function (?string $phone): ?string {
            if (! $phone) return null;
            $digits = preg_replace('/\D+/', '', $phone);
            if (str_starts_with($digits, '00970')) $digits = substr($digits, 5);
            elseif (str_starts_with($digits, '970')) $digits = substr($digits, 3);
            if (str_starts_with($digits, '0')) $digits = substr($digits, 1);
            return $digits;
        };
        $request->merge(['phone'=>$normalize($request->input('phone')),'mobile'=>$normalize($request->input('mobile'))]);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => ['required','string','max:30',Rule::unique('customers','phone')->whereNull('deleted_at')],
            'mobile' => ['nullable','string','max:30',Rule::unique('customers','mobile')->whereNull('deleted_at')],
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'area' => 'nullable|string|max:100',
            'category' => 'nullable|string|in:regular,important,vip,new,inactive,follow_up,complaints',
            'notes' => 'nullable|string|max:2000',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $phones=array_values(array_filter([$data['phone']??null,$data['mobile']??null]));
        if (Customer::query()->where(fn($q)=>$q->whereIn('phone',$phones)->orWhereIn('mobile',$phones))->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['phone'=>'رقم الهاتف مسجل لعميل آخر.']);
        }

        $customer = $this->callCenterService->createCustomer($data);

        return $this->success('تم إنشاء العميل', $customer, 201);
    }

    /**
     * GET /api/call-center/customers/search
     */
    public function searchCustomers(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:1|max:100']);

        $customers = $this->callCenterService->searchCustomers(
            $request->input('q'),
            $request->input('limit', 20)
        );

        return $this->success('نتائج البحث', $customers);
    }

    public function customerDirectory(Request $request): JsonResponse
    {
        $data=$request->validate(['q'=>'nullable|string|max:100','category'=>'nullable|string|max:50','status'=>'nullable|in:active,inactive,blocked','city'=>'nullable|string|max:100','area'=>'nullable|string|max:100','open_complaints'=>'nullable|boolean','nearby_occasion'=>'nullable|boolean','has_financial_profile'=>'nullable|boolean','loyalty_min'=>'nullable|integer|min:0','loyalty_max'=>'nullable|integer|min:0','last_order_from'=>'nullable|date','last_order_to'=>'nullable|date','source'=>'nullable|string|max:50','branch_id'=>'nullable|integer|exists:branches,id','sort'=>'nullable|in:name,created_at,loyalty_points,last_order_at,orders_count,open_complaints_count','direction'=>'nullable|in:asc,desc','per_page'=>'nullable|integer|min:10|max:100']);
        $user=$request->user();$branch=$user?->hasRole('super-admin')?($data['branch_id']??null):$user?->branch_id;
        $q=Customer::query()->with(['branch:id,name','address'])->withCount(['orders','complaints as open_complaints_count'=>fn($x)=>$x->whereNotIn('status',['resolved','closed'])])->withMax('orders','created_at')
            ->when($data['q']??null,fn($x,$v)=>$x->where(fn($y)=>$y->where('name','like',"%$v%")->orWhere('phone','like',"%$v%")->orWhere('mobile','like',"%$v%")->orWhere('code','like',"%$v%")))
            ->when($data['category']??null,fn($x,$v)=>$x->where('category',$v))->when($data['status']??null,fn($x,$v)=>$x->where('status',$v))->when($data['city']??null,fn($x,$v)=>$x->where(fn($y)=>$y->where('city','like',"%$v%")->orWhereHas('addresses',fn($a)=>$a->where('city','like',"%$v%"))))
            ->when($data['area']??null,fn($x,$v)=>$x->whereHas('addresses',fn($a)=>$a->where('area','like',"%$v%")))->when($request->boolean('open_complaints'),fn($x)=>$x->whereHas('complaints',fn($c)=>$c->whereNotIn('status',['resolved','closed'])))
            ->when($request->boolean('nearby_occasion'),fn($x)=>$x->whereHas('occasions',fn($o)=>$o->where('is_active',true)->whereRaw("DATE_FORMAT(date, '%m-%d') between DATE_FORMAT(CURDATE(), '%m-%d') and DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 30 DAY), '%m-%d')")))
            ->when($request->boolean('has_financial_profile'),fn($x)=>$x->where(fn($y)=>$y->where('credit_limit','>',0)->orWhere('opening_balance','!=',0)))
            ->when(isset($data['loyalty_min']),fn($x)=>$x->where('loyalty_points','>=',$data['loyalty_min']))->when(isset($data['loyalty_max']),fn($x)=>$x->where('loyalty_points','<=',$data['loyalty_max']))
            ->when($data['last_order_from']??null,fn($x,$v)=>$x->whereRaw('(select max(o.created_at) from orders o where o.customer_id = customers.id and o.deleted_at is null) >= ?',[$v.' 00:00:00']))->when($data['last_order_to']??null,fn($x,$v)=>$x->whereRaw('(select max(o.created_at) from orders o where o.customer_id = customers.id and o.deleted_at is null) <= ?',[$v.' 23:59:59']))
            ->when($data['source']??null,fn($x,$v)=>$x->whereHas('orders',fn($o)=>$o->where('source',$v)))->when($branch,fn($x,$v)=>$x->where(fn($y)=>$y->where('branch_id',$v)->orWhereHas('orders',fn($o)=>$o->where('branch_id',$v))));
        $sort=$data['sort']??'created_at';$column=$sort==='last_order_at'?'orders_max_created_at':$sort;
        return $this->success('CRM customer directory',$q->orderBy($column,$data['direction']??'desc')->paginate($data['per_page']??25)->withQueryString());
    }

    /**
     * GET /api/call-center/customers/{customer}/profile
     */
    public function customerProfile(Customer $customer): JsonResponse
    {
        $profile = $this->callCenterService->getCustomerProfile($customer->id);

        return $this->success('ملف العميل', $profile);
    }

    /** Update the stored (manual) call-center classification. */
    public function updateCustomerClassification(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'category' => 'required|string|in:regular,important,vip,new,inactive,follow_up,complaints',
        ]);

        $customer->update(['category' => $data['category']]);

        return $this->success('تم تحديث تصنيف العميل', [
            'id' => $customer->id,
            'category' => $customer->category,
        ]);
    }

    /**
     * GET /api/call-center/customers/{customer}/full-profile
     */
    public function customerFullProfile(Customer $customer): JsonResponse
    {
        return $this->success(
            'ملف العميل الكامل',
            $this->callCenterService->getCustomerFullProfile($customer->id)
        );
    }

    /**
     * GET /api/call-center/customers/{customer}/orders
     */
    public function customerOrders(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'cursor' => 'nullable|integer',
        ]);

        $orders = $this->callCenterService->getCustomerOrders(
            $customer->id,
            $data['per_page'] ?? 20,
            $data['cursor'] ?? null
        );

        return $this->success('طلبات العميل', $orders);
    }

    /**
     * GET /api/call-center/orders/{order}
     */
    public function orderDetails(int $order): JsonResponse
    {
        $details = $this->callCenterService->getOrderDetails($order);

        return $this->success('تفاصيل الطلب', $details);
    }

    /**
     * GET /api/call-center/customers/{customer}/favorites
     */
    public function customerFavorites(Request $request, Customer $customer): JsonResponse
    {
        $limit = $request->input('limit', 20);

        $favorites = $this->callCenterService->getCustomerFavorites($customer->id, $limit);

        return $this->success('الأصناف المفضلة', $favorites);
    }

    /**
     * GET /api/call-center/customers/{customer}/addresses
     */
    public function customerAddresses(Customer $customer): JsonResponse
    {
        $addresses = $this->callCenterService->getCustomerAddresses($customer->id);

        return $this->success('عناوين العميل', $addresses);
    }

    /**
     * GET /api/call-center/customers/{customer}/complaints
     */
    public function customerComplaints(Request $request, Customer $customer): JsonResponse
    {
        $perPage = $request->input('per_page', 20);

        $complaints = $this->callCenterService->getCustomerComplaints($customer->id, $perPage);

        return $this->success('شكاوى العميل', $complaints);
    }

    /**
     * GET /api/call-center/customers/{customer}/alerts
     */
    public function customerAlerts(Customer $customer): JsonResponse
    {
        $alerts = $this->callCenterService->getCustomerAlerts($customer->id);

        return $this->success('تنبيهات العميل', $alerts);
    }

    /**
     * GET /api/call-center/customers/analytics
     */
    public function analytics(): JsonResponse
    {
        $data = $this->callCenterService->getDashboardAnalytics();

        return $this->success('تحليلات العملاء', $data);
    }

    /**
     * GET /api/call-center/customers/top
     */
    public function topCustomers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => 'nullable|in:today,yesterday,week,last_7_days,last_30_days,month,last_month,year,custom',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'sort_by' => 'nullable|in:orders_count,total_spent,avg_order_value,cancelled_count,open_complaints',
            'sort_dir' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
            'branch_id' => 'nullable|integer',
        ]);

        $customers = $this->callCenterService->getTopCustomers($data);

        return $this->success('أفضل العملاء', $customers);
    }

    // ──────────────────────────────────────────
    // COMPLAINTS MANAGEMENT
    // ──────────────────────────────────────────

    /**
     * GET /api/call-center/complaints
     */
    public function complaintsIndex(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => 'nullable|string',
            'priority' => 'nullable|string',
            'type' => 'nullable|string',
            'customer_id' => 'nullable|integer',
            'assigned_to' => 'nullable|integer',
            'search' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $complaints = $this->callCenterService->getAllComplaints($data, $data['per_page'] ?? 20);

        return $this->success('الشكاوى', $complaints);
    }

    /**
     * POST /api/call-center/complaints
     */
    public function storeComplaint(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'order_id' => 'nullable|integer|exists:orders,id',
            'invoice_id' => 'nullable|integer|exists:invoices,id',
            'assigned_to' => 'nullable|integer|exists:employees,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|string|max:50',
            'priority' => 'nullable|in:low,normal,high,critical',
            'severity' => 'nullable|in:info,warning,critical',
            'is_sensitive' => 'nullable|boolean',
        ]);

        $complaint = $this->callCenterService->createComplaint($data, $request->user()->id);

        return $this->success('تم إنشاء الشكوى', $complaint->load(['customer:id,name,phone', 'order:id,order_number']), 201);
    }

    /**
     * GET /api/call-center/complaints/{complaint}
     */
    public function showComplaint(CustomerComplaint $complaint): JsonResponse
    {
        $complaint->load(['customer:id,name,phone', 'order:id,order_number', 'invoice:id,number', 'assignedTo:id,name', 'createdBy:id,name']);

        return $this->success('تفاصيل الشكوى', $complaint);
    }

    /**
     * PATCH /api/call-center/complaints/{complaint}
     */
    public function updateComplaint(Request $request, CustomerComplaint $complaint): JsonResponse
    {
        $data = $request->validate([
            'status' => 'nullable|in:new,open,in_progress,waiting_customer,resolved,closed,cancelled',
            'assigned_to' => 'nullable|integer|exists:employees,id',
            'priority' => 'nullable|in:low,normal,high,critical',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'resolution_notes' => 'nullable|string',
            'resolution_result' => 'nullable|string|max:50',
            'type' => 'nullable|string|max:50',
            'is_sensitive' => 'nullable|boolean',
        ]);

        if (isset($data['status'])) {
            $oldStatus = $complaint->status;
            $complaint = $this->callCenterService->updateComplaintStatus(
                $complaint->id,
                $data['status'],
                $request->user()->id,
                $data['resolution_notes'] ?? null
            );
            unset($data['status'], $data['resolution_notes']);
        }

        if (!empty($data)) {
            $complaint->update($data);
        }

        $complaint->refresh()->load(['customer:id,name,phone', 'order:id,order_number']);

        return $this->success('تم تحديث الشكوى', $complaint);
    }

    /**
     * POST /api/call-center/complaints/{complaint}/followups
     */
    public function addFollowup(Request $request, CustomerComplaint $complaint): JsonResponse
    {
        $data = $request->validate([
            'notes' => 'required|string',
            'action' => 'nullable|string|max:50',
            'followup_type' => 'nullable|in:note,call,action,system',
        ]);

        $followup = $this->callCenterService->addFollowup(
            $complaint->id,
            $request->user()->id,
            $data['action'] ?? 'note_added',
            $data['notes'],
            $data['followup_type'] ?? 'note'
        );

        $followup->load('user:id,name');

        return $this->success('تم إضافة متابعة', $followup, 201);
    }

    /**
     * GET /api/call-center/complaints/{complaint}/timeline
     */
    public function complaintTimeline(CustomerComplaint $complaint): JsonResponse
    {
        $timeline = $this->callCenterService->getComplaintTimeline($complaint->id);

        return $this->success('الجدول الزمني للشكوى', $timeline);
    }

    // ──────────────────────────────────────────
    // ADDRESSES CRUD
    // ──────────────────────────────────────────

    /**
     * POST /api/call-center/customers/{customer}/addresses
     */
    public function storeAddress(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'label' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:100',
            'area' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'street' => 'nullable|string|max:255',
            'landmark' => 'nullable|string|max:255',
            'building_no' => 'nullable|string|max:50',
            'floor' => 'nullable|string|max:20',
            'apartment' => 'nullable|string|max:20',
            'delivery_notes' => 'nullable|string',
            'phone' => 'nullable|string|max:30',
            'map_url' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'is_default' => 'nullable|boolean',
        ]);

        $address = $this->callCenterService->createAddress($customer->id, $data);

        return $this->success('تم إضافة العنوان', $address, 201);
    }

    /**
     * PATCH /api/call-center/customer-addresses/{address}
     */
    public function updateAddress(Request $request, CustomerAddress $address): JsonResponse
    {
        $data = $request->validate([
            'label' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:100',
            'area' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'street' => 'nullable|string|max:255',
            'landmark' => 'nullable|string|max:255',
            'building_no' => 'nullable|string|max:50',
            'floor' => 'nullable|string|max:20',
            'apartment' => 'nullable|string|max:20',
            'delivery_notes' => 'nullable|string',
            'phone' => 'nullable|string|max:30',
            'map_url' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $updated = $this->callCenterService->updateAddress($address->id, $data, $address->customer_id);

        return $this->success('تم تحديث العنوان', $updated);
    }

    /**
     * POST /api/call-center/customer-addresses/{address}/use
     */
    public function markAddressUsed(CustomerAddress $address): JsonResponse
    {
        $this->callCenterService->markAddressUsed($address->id);

        return $this->success('تم تحديث آخر استخدام');
    }

    // ──────────────────────────────────────────
    // OCCASIONS CRUD
    // ──────────────────────────────────────────

    /**
     * GET /api/call-center/customers/{customer}/occasions
     */
    public function customerOccasions(Customer $customer): JsonResponse
    {
        $occasions = $this->callCenterService->getCustomerOccasions($customer->id);

        return $this->success('مناسبات العميل', $occasions);
    }

    /**
     * POST /api/call-center/customers/{customer}/occasions
     */
    public function storeOccasion(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'occasion_type' => 'required|string|max:50',
            'title' => 'required|string|max:255',
            'date' => 'required|date',
            'repeats_annually' => 'nullable|boolean',
            'notes' => 'nullable|string',
            'preferred_contact_method' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
        ]);

        $occasion = $this->callCenterService->createOccasion($customer->id, $data, $request->user()->id);

        return $this->success('تم إضافة المناسبة', $occasion, 201);
    }

    /**
     * PATCH /api/call-center/customer-occasions/{occasion}
     */
    public function updateOccasion(Request $request, CustomerOccasion $occasion): JsonResponse
    {
        $data = $request->validate([
            'occasion_type' => 'nullable|string|max:50',
            'title' => 'nullable|string|max:255',
            'date' => 'nullable|date',
            'repeats_annually' => 'nullable|boolean',
            'notes' => 'nullable|string',
            'preferred_contact_method' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
        ]);

        $updated = $this->callCenterService->updateOccasion($occasion->id, $data);

        return $this->success('تم تحديث المناسبة', $updated);
    }

    /**
     * DELETE /api/call-center/customer-occasions/{occasion}
     */
    public function deleteOccasion(CustomerOccasion $occasion): JsonResponse
    {
        $this->callCenterService->deleteOccasion($occasion->id);

        return $this->success('تم حذف المناسبة');
    }

    /**
     * GET /api/call-center/occasions?range=today|tomorrow|week|month|upcoming|past
     */
    public function occasionsByRange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'range' => 'nullable|in:today,tomorrow,week,month,upcoming,past',
            'customer_id' => 'nullable|integer|exists:customers,id',
        ]);

        $occasions = $this->callCenterService->getOccasionsByRange(
            $data['range'] ?? 'today',
            $data['customer_id'] ?? null
        );

        return $this->success('المناسبات', $occasions);
    }

    // ──────────────────────────────────────────
    // NOTES CRUD
    // ──────────────────────────────────────────

    /**
     * GET /api/call-center/customers/{customer}/notes
     */
    public function customerNotes(Customer $customer): JsonResponse
    {
        $notes = $this->callCenterService->getCustomerNotes($customer->id);

        return $this->success('ملاحظات العميل', $notes);
    }

    /**
     * POST /api/call-center/customers/{customer}/notes
     */
    public function storeNote(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'content' => 'required|string',
            'type' => 'nullable|string|max:50',
            'importance' => 'nullable|in:low,normal,high,urgent',
            'show_during_order' => 'nullable|boolean',
            'order_id' => 'nullable|integer|exists:orders,id',
        ]);

        $note = $this->callCenterService->createNote($customer->id, $data, $request->user()->id);

        return $this->success('تم إضافة الملاحظة', $note, 201);
    }

    /**
     * GET /api/call-center/customers/{customer}/important-notes
     */
    public function customerImportantNotes(Customer $customer): JsonResponse
    {
        $notes = $this->callCenterService->getImportantNotes($customer->id);

        return $this->success('الملاحظات المهمة', $notes);
    }

    // ──────────────────────────────────────────
    // QUICK CREATE CUSTOMER (WITH ADDRESS + BIRTHDAY)
    // ──────────────────────────────────────────

    /**
     * POST /api/call-center/customers/quick-create
     * Creates customer + initial address + optional birth date occasion
     * No GL account is provisioned.
     */
    public function quickCreateCustomer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'mobile' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'category' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'birth_date' => 'nullable|date',
            'address_label' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'area' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'street' => 'nullable|string|max:255',
            'landmark' => 'nullable|string|max:255',
            'building_no' => 'nullable|string|max:50',
            'floor' => 'nullable|string|max:20',
            'apartment' => 'nullable|string|max:20',
            'delivery_notes' => 'nullable|string',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $data['created_by'] = $request->user()->id ?? null;
        $customer = $this->callCenterService->createCustomer($data);

        return $this->success('تم إنشاء العميل', $customer->load('addresses'), 201);
    }
}
